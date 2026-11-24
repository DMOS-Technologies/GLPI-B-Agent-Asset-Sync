# DMOS AssetSync - Slave

DMOS AssetSync - Master uses GLPI 11's legacy REST API for discovery and session management. The Agent's CLI setup provisions a dedicated API client and account. The initial profile has zero resource permissions and access to the Root entity without recursion. Controller A owns the routes, scheduling, status, and Last contact timestamp.

## Local Docker Desktop

Windows opens the GLPI B web UI at `http://127.0.0.1:8081`. Inside A's GLPI container, the API base URL is `http://host.docker.internal:8081/apirest.php`.

The A Compose override resolves `host.docker.internal` to B's private address inside A's GLPI container. The private bridge preserves A's source address (`172.30.250.2`), which the GLPI B API client allowlists. This hostname mapping applies to all connections to `host.docker.internal` inside A's GLPI container.

For a fresh local setup, create the shared bridge once, then start both Compose projects from their own directories. Use the `compose.override.yaml` in the Controller workspace when starting A.

```powershell
docker network create --driver bridge --internal --subnet 172.30.250.0/29 glpi-asset-sync
docker compose up -d --wait
```

Run the following command from the Agent workspace to set up a new local integration. The command preserves existing credentials and checks that the account still has its initial session-only permissions.

```powershell
docker compose exec -T --user www-data glpi php plugins/assetsyncagent/bin/configure-authentication.php 172.30.250.2
```

## Credentials in GLPI B

- **Application Token** is under **Setup → General → API → API clients → Asset Sync Controller A**.
- **User Token** is under **Administration → Users → asset-sync-controller-a → Passwords and access keys → API token**.

Send the Application Token in the `App-Token` header. For `GET /initSession`, send the User Token in the `Authorization` header using the `user_token` scheme. For `GET /killSession`, send the returned session token in the `Session-Token` header. Use headers for every credential and omit them from logs. Use HTTPS with certificate validation outside local development, and allowlist the Controller address that GLPI B sees in that deployment.

## Route discovery

On GLPI A, open **Setup → DMOS AssetSync - Master → Routes → Add route**, select the saved GLPI B connection, and click **Load route options**. The Controller uses the saved credentials for discovery and validates the selected options again when a user saves the route. Both actions run on demand.

1. Open a session with `GET /initSession`.
2. Read the active profile's entity IDs and names with `GET /getMyEntities?is_recursive=1`. Keep entity ID `0` when it is returned. On this instance, GLPI B names its root entity `BSJV`.
3. Read the All Assets catalog from `cfg_glpi.asset_types` in `GET /getGlpiConfig`. Use the exact itemtype strings for route values, including the namespace of custom types. The catalog includes active custom types and types with zero records.
4. Close the session with `GET /killSession`, including when a discovery request fails. Use the `App-Token` and `Session-Token` headers for the discovery and cleanup requests.

Grant additional entities under **Administration → Users → asset-sync-controller-a → Authorizations**. Assign the session-only profile to each approved entity. Enable a recursive grant only when every descendant is in scope. The CLI setup command above provisions the initial root-only access and will ask for manual review if it finds additional grants.

Controller A obtains mapping metadata with `GET /listSearchOptions/{itemtype}` in the same API session. It URL-encodes the exact itemtype as one path segment, including the namespace separators.

## Sync endpoint

Controller A sends `POST /plugins/assetsyncagent/front/sync.php` relative to GLPI B's installation root. Agent B validates `App-Token` and `Session-Token` through GLPI's legacy API and uses the API client's configured source restrictions and the session account's current GLPI permissions.

Asset read access is required for probes and comparisons. Creation and writes also require the corresponding asset permissions. A Fields container must grant read or write access for the operation. A profile with its initial session-only grant can discover routes and open sessions.

- `probe` resolves B's saved field descriptors and reads the active asset for the exact item type, entity, and serial. Each returned field includes its portable value and its own history timestamp, or `null` when no edit history is available. Native custom asset definitions need GLPI's History capacity for this timestamp.
- `apply` uses the probe's asset ID and expected field values to validate updates. Creates use the requested entity and serial and give mapped fields precedence over initial values. A conflict requires a fresh probe. A short lock serializes applies within the same asset type and entity, and the endpoint acknowledges a write after the transaction commits.
- `changes` takes `itemtype`, `entity_id`, the mapped `fields`, and a `cursor`. Pass `null` to start at the latest relevant audit entry. Each later call returns the next `cursor`, `more`, and `changes` with a serial and mapped search option IDs for each affected asset. It reads up to 500 history entries per page without transferring field values. Agent writes are excluded. Direct database writes and edits without GLPI history are outside this feed.

Dropdowns travel as labels or full paths. Decimal values should travel as strings to preserve precision, and datetimes use UTC.

GLPI B's Historical tab records asset creation and the fields supplied on creation or changed by a later synchronization. Sync entries show the affected field in the **Field** column. The **Update** column shows its initial value or its previous and new values when those values were recorded. Long values are shortened. Notes show counts, and sensitive fields omit their values. GLPI lists these events under **Others** in its History filter. The CSV export includes the affected field name in the update description.

Custom asset definitions need GLPI's History capacity. The asset's Last Update reflects creation and field changes, including Notes and Fields plugin changes. A sync with no changed fields leaves Last Update unchanged.

The Agent returns stable error codes. Controller A decides when to retry.
