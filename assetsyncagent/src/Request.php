<?php

namespace AssetSyncAgent;

use Entity;
use Glpi\Api\APIRest;
use Glpi\Search\SearchOption;
use GLPIKey;
use JsonException;
use Profile;
use RuntimeException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;
use User;

require_once __DIR__ . '/SyncException.php';

final class Request extends APIRest
{
    private const MAX_BODY_BYTES = 1048576;
    private const MAX_BATCH_BODY_BYTES = 16777216;
    private const MAX_BATCH_ASSETS = 100;

    public static function respond(): JsonResponse
    {
        $includeErrorDetails = false;
        // GLPI database warnings can contain SQL and submitted values.
        set_error_handler(static function (int $severity, string $message, string $file): bool
        {
            if ((error_reporting() & $severity) === 0
                || ($severity & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR)) === 0)
            {
                return true;
            }

            // PHP keeps the first constant value. A duplicate in another plugin can be skipped.
            if ($severity === E_WARNING && str_starts_with($message, 'Constant ')
                && str_ends_with($message, ' already defined') && self::isOtherPluginFile($file))
            {
                return true;
            }

            throw new RuntimeException('GLPI operation failed');
        });

        try
        {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
            {
                return self::error('method_not_allowed', 405, ['Allow' => 'POST']);
            }

            $request = new self();
            $request->authenticate();
            $body = self::readJson();
            if (($body['operation'] ?? null) === 'batch')
            {
                return new JsonResponse($request->batch($body), headers: ['Cache-Control' => 'no-store']);
            }
            $request->selectEntity($body['entity_id'] ?? null);
            $includeErrorDetails = ($body['include_error_details'] ?? false) === true;

            require_once __DIR__ . '/Sync.php';
            return new JsonResponse(Sync::handle($body), headers: ['Cache-Control' => 'no-store']);
        }
        catch (SyncException $exception)
        {
            $details = $includeErrorDetails && $exception->getFieldId() !== null
                ? ['field_id' => $exception->getFieldId()]
                : [];
            return self::error($exception->getMessage(), $exception->getCode(), details: $details);
        }
        catch (Throwable)
        {
            return self::error('internal_error', 500);
        }
        finally
        {
            // Keep the legacy session open for Controller A's killSession call.
            // Discard the per-request GLPI profile and entity context.
            if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '')
            {
                @session_abort();
            }
            restore_error_handler();
        }
    }

    private function batch(array $body): array
    {
        $items = $body['requests'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > self::MAX_BATCH_ASSETS)
        {
            throw new SyncException('invalid_request', 400);
        }

        // Validate the entire set of correlation IDs before anything can be written.
        $ids = [];
        foreach ($items as $item)
        {
            $id = is_array($item) ? ($item['c_id'] ?? null) : null;
            if (!is_int($id) || $id < 1 || isset($ids[$id]))
            {
                throw new SyncException('invalid_request', 400);
            }
            $ids[$id] = true;
        }

        require_once __DIR__ . '/Sync.php';
        $session = $_SESSION;
        $results = [];
        foreach ($items as $item)
        {
            $result = ['c_id' => $item['c_id']];
            try
            {
                if (!in_array($item['operation'] ?? null, ['probe', 'apply'], true))
                {
                    throw new SyncException('invalid_request', 400);
                }
                $this->selectEntity($item['entity_id'] ?? null);
                if (is_string($item['itemtype'] ?? null))
                {
                    // Search options can depend on the scope selected for the previous item.
                    SearchOption::clearSearchOptionCache($item['itemtype']);
                }
                $result += Sync::handle($item);
            }
            catch (SyncException $exception)
            {
                $result += ['ok' => false, 'error' => $exception->getMessage()];
                if (($item['include_error_details'] ?? false) === true && $exception->getFieldId() !== null)
                {
                    $result['field_id'] = $exception->getFieldId();
                }
            }
            catch (Throwable)
            {
                // Each asset has its own transaction. An unexpected failure must not stop other assets.
                $result += ['ok' => false, 'error' => 'internal_error'];
            }
            finally
            {
                $_SESSION = $session;
            }
            $results[] = $result;
        }
        return ['ok' => true, 'results' => $results];
    }

    private static function isOtherPluginFile(string $file): bool
    {
        if (str_starts_with($file, dirname(__DIR__) . DIRECTORY_SEPARATOR))
        {
            return false;
        }

        // GLPI includes both manually installed and Marketplace plugins in these directories.
        foreach (GLPI_PLUGINS_DIRECTORIES as $directory)
        {
            $pluginRoot = realpath($directory);
            if ($pluginRoot !== false && str_starts_with($file, rtrim($pluginRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
            {
                return true;
            }
        }

        return false;
    }

    private function authenticate(): void
    {
        $appToken = $_SERVER['HTTP_APP_TOKEN'] ?? '';
        $sessionToken = $_SERVER['HTTP_SESSION_TOKEN'] ?? '';
        if ($appToken === '' || $sessionToken === '')
        {
            throw new SyncException('authentication_required', 401);
        }
        if (!preg_match('/\A[\x21-\x7e]{1,1024}\z/', $appToken)
            || !preg_match('/\A[A-Za-z0-9+\/=]{1,1024}\z/', $sessionToken))
        {
            throw new SyncException('authentication_failed', 401);
        }
        if (session_status() !== PHP_SESSION_NONE || session_id() !== '')
        {
            throw new SyncException('authentication_failed', 401);
        }

        // GLPI returns only active API clients that allow the request's source address.
        $this->initApi();
        if (!in_array($appToken, $this->app_tokens, true))
        {
            throw new SyncException('authentication_failed', 401);
        }

        // Legacy initSession encodes the encrypted PHP session ID in another layer of base64.
        $encryptedId = base64_decode($sessionToken, true);
        $key = new GLPIKey();
        $keyData = $key->get();
        if ($encryptedId === false || $keyData === null)
        {
            throw new SyncException('authentication_failed', 401);
        }
        try
        {
            $sessionId = $key->decrypt($encryptedId, $keyData);
        }
        catch (Throwable)
        {
            throw new SyncException('authentication_failed', 401);
        }
        if (!is_string($sessionId) || !preg_match('/\A[A-Za-z0-9,\-]{16,256}\z/', $sessionId))
        {
            throw new SyncException('authentication_failed', 401);
        }

        ini_set('session.use_strict_mode', '1');
        $this->parameters = ['session_token' => $sessionId];
        $this->retrieveSession();
        $this->checkSessionToken();

        // A legacy REST session does not have the browser session's valid_id flag.
        $userId = (int) Session::getLoginUserID();
        $account = new User();
        $now = $_SESSION['glpi_currenttime'];
        if (isset($_SESSION['valid_id']) || $userId < 1 || !$account->getFromDB($userId)
            || !(int) $account->fields['is_active'] || (int) $account->fields['is_deleted']
            || ($account->fields['begin_date'] !== null && $account->fields['begin_date'] > $now)
            || ($account->fields['end_date'] !== null && $account->fields['end_date'] < $now))
        {
            throw new SyncException('authentication_failed', 401);
        }

        $_SESSION['glpi_use_mode'] = Session::NORMAL_MODE;
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    private function selectEntity(mixed $entityId): void
    {
        if (!is_int($entityId) || $entityId < 0)
        {
            throw new SyncException('invalid_request', 400);
        }

        // Reload grants so an already-open session cannot retain a revoked entity.
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        Session::initEntityProfiles((int) Session::getLoginUserID());
        $profile = $_SESSION['glpiprofiles'][$profileId] ?? [];
        if ($profileId < 1 || $profile === [])
        {
            throw new SyncException('authentication_failed', 401);
        }

        $entity = new Entity();
        if (!$entity->getFromDB($entityId))
        {
            throw new SyncException('entity_not_allowed', 403);
        }
        $grants = $profile['entities'] ?? [];
        $authorized = isset($grants[$entityId]);
        if (!$authorized)
        {
            foreach (getAncestorsOf(Entity::getTable(), $entityId) as $ancestorId)
            {
                if (!empty($grants[$ancestorId]['is_recursive']))
                {
                    $authorized = true;
                    break;
                }
            }
        }
        if (!$authorized)
        {
            throw new SyncException('entity_not_allowed', 403);
        }

        $currentProfile = new Profile();
        if (!$currentProfile->getFromDB($profileId))
        {
            throw new SyncException('authentication_failed', 401);
        }
        $currentProfile->cleanProfile();
        $_SESSION['glpiactiveprofile'] = $currentProfile->fields + ['entities' => $grants];
        if (!Session::changeActiveEntities($entityId, false))
        {
            throw new SyncException('entity_not_allowed', 403);
        }
    }

    private static function readJson(): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($contentType !== 'application/json')
        {
            throw new SyncException('unsupported_media_type', 415);
        }
        $json = file_get_contents('php://input', length: self::MAX_BATCH_BODY_BYTES + 1);
        if ($json === false)
        {
            throw new SyncException('invalid_request', 400);
        }
        if (strlen($json) > self::MAX_BATCH_BODY_BYTES)
        {
            throw new SyncException('request_too_large', 413);
        }
        try
        {
            $body = json_decode($json, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        catch (JsonException)
        {
            throw new SyncException('invalid_request', 400);
        }
        if (!is_array($body) || array_is_list($body))
        {
            throw new SyncException('invalid_request', 400);
        }
        if (($body['operation'] ?? null) !== 'batch' && strlen($json) > self::MAX_BODY_BYTES)
        {
            throw new SyncException('request_too_large', 413);
        }
        return $body;
    }

    public function returnResponse($response, $httpcode = 200, $additionalheaders = [])
    {
        // The legacy API's helpers exit via returnResponse on authentication failure.
        throw new SyncException('authentication_failed', 401);
    }

    private static function error(string $code, int $status, array $headers = [], array $details = []): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code] + $details, $status, $headers + ['Cache-Control' => 'no-store']);
    }
}
