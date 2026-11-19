<?php

// An administrator runs this explicitly inside GLPI B. Plugin installation
// does not change GLPI's global API settings or create accounts automatically.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$controllerAddress = $argv[1] ?? '';
if (!filter_var($controllerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ip2long($controllerAddress) === 0) {
    fwrite(STDERR, "Usage: php configure-authentication.php <Controller IPv4 address as seen by GLPI B>\n");
    exit(1);
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function requireSuccess(bool $success, string $message): void
{
    if (!$success) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

try {
    (new Glpi\Kernel\Kernel())->boot();
    Session::initVars();

    $sourceIp = ip2long($controllerAddress);
    foreach ($DB->request(['FROM' => APIClient::getTable(), 'WHERE' => ['is_active' => 1]]) as $row) {
        $matchesAddress = $sourceIp >= ($row['ipv4_range_start'] ?? 0)
            && $sourceIp <= ($row['ipv4_range_end'] ?? 4294967295);
        requireSuccess(!$matchesAddress || !empty($row['app_token']),
            'An active tokenless API client permits this address. Restrict that client before continuing.');
    }

    // A session needs a profile and an entity. This profile grants no resource rights.
    $profile = new Profile();
    $profileName = 'Asset Sync Agent - session only';
    if (!$profile->getFromDBByCrit(['name' => $profileName])) {
        requireSuccess((bool) $profile->add(['name' => $profileName, 'interface' => 'central', 'is_default' => 0]),
            'Could not create the session-only profile.');
    }
    $profileId = $profile->getID();
    requireSuccess($profile->fields['interface'] === 'central' && !(int) $profile->fields['is_default'],
        'The dedicated profile must use the standard interface and must not be the default profile.');
    foreach ($DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $profileId]]) as $right) {
        requireSuccess((int) $right['rights'] === 0, 'The session-only profile has a resource permission.');
    }

    $user = new User();
    $accountName = 'asset-sync-controller-a';
    $newUser = !$user->getFromDBByCrit(['name' => $accountName]);
    if ($newUser) {
        requireSuccess((bool) $user->add(['name' => $accountName, 'profiles_id' => $profileId, 'entities_id' => 0]),
            'Could not create the dedicated service account.');
    }
    requireSuccess((int) $user->fields['authtype'] === Auth::DB_GLPI && (int) $user->fields['is_active'] === 1
        && !(int) $user->fields['is_deleted'] && empty($user->fields['password']),
        'The service account must be an active local account without a password.');

    $accountId = $user->getID();
    $hasSessionProfile = false;
    foreach ($DB->request(['FROM' => Profile_User::getTable(), 'WHERE' => ['users_id' => $accountId]]) as $grant) {
        $isSessionProfile = (int) $grant['profiles_id'] === $profileId
            && (int) $grant['entities_id'] === 0 && !(int) $grant['is_recursive'];
        if ($isSessionProfile) {
            $hasSessionProfile = true;
            continue;
        }

        // GLPI assigns its default profile to new local users. Remove that grant
        // before issuing credentials. Existing accounts require manual review.
        requireSuccess($newUser, 'The service account has an unexpected profile or entity grant.');
        requireSuccess((new Profile_User())->delete(['id' => $grant['id']], true),
            'Could not remove the automatic authorization from the new service account.');
    }
    if (!$hasSessionProfile) {
        requireSuccess((bool) (new Profile_User())->add([
            'users_id' => $accountId,
            'profiles_id' => $profileId,
            'entities_id' => 0,
            'is_recursive' => 0,
        ]), 'Could not assign the nonrecursive root entity authorization.');
    }

    $client = new APIClient();
    $clientName = 'Asset Sync Controller A';
    $clientSettings = [
        'name' => $clientName,
        'is_active' => 1,
        'ipv4_range_start' => $controllerAddress,
        'ipv4_range_end' => $controllerAddress,
        // A blank IPv6 field would allow any IPv6 peer. :: cannot be a TCP peer.
        'ipv6' => '::',
        'dolog_method' => APIClient::DOLOG_DISABLED,
    ];
    if ($client->getFromDBByCrit(['name' => $clientName])) {
        $clientSettings['id'] = $client->getID();
        $clientSettings['_reset_app_token'] = empty($client->fields['app_token']);
        requireSuccess($client->update($clientSettings), 'Could not update the dedicated API client.');
    } else {
        $clientSettings['_reset_app_token'] = true;
        requireSuccess((bool) $client->add($clientSettings), 'Could not create the dedicated API client.');
    }

    // GLPI generates and encrypts both tokens. Do not print or rotate them on rerun.
    requireSuccess((bool) $user->getAuthToken('api_token'), 'Could not generate the service account API token.');
    Config::setConfigurationValues('core', [
        'enable_api' => 1,
        'enable_api_login_external_token' => 1,
        'enable_api_login_credentials' => 0,
    ]);
    echo "GLPI B legacy API configured for Asset Sync Controller A.\n";
} catch (Throwable) {
    // Exception details can contain database statements or credentials.
    fwrite(STDERR, "GLPI rejected the authentication setup. Review the non-secret configuration and retry.\n");
    exit(1);
}
