<?php
namespace Zero\Module\Auth\Admin;

use Zero\Core\AdminResponse;
use Zero\Core\Database;
use Zero\Core\Attribute\{RequirePermission, AllowedMethods, RequiredParams};
use Zero\Module\Auth\OAuth\Provider;

/**
 * Admin: OAuth provider registry.
 *
 * Enable/disable the third-party sign-in providers in the `oauth_provider`
 * table. The flag is honored instantly and everywhere — the login buttons,
 * /user/profile, and Auth::assertProviderAvailable()/Provider::available()
 * all gate on it — so no other wiring is needed here.
 *
 * Route: /admin/auth
 */
#[RequirePermission('allow.admin')]
class AuthAdmin extends AdminResponse {

    public function index() {
        $this->setBreadcrumbs([
            ['label' => 'OAuth Providers', 'url' => '/admin/auth'],
        ]);
        $this->providers        = Provider::all();
        $this->connectionCounts = $this->connectionCounts();
        $this->respond(__DIR__ . '/view/index.php');
    }

    /**
     * Toggle a provider's admin-enabled flag. POST { slug, enabled }.
     * Returns JSON via success()/error() for the AJAX toggle switch.
     */
    #[AllowedMethods('POST')]
    #[RequiredParams('slug')]
    public function toggle() {
        $slug = strtolower(trim($_POST['slug'] ?? ''));
        if ($slug === '' || !preg_match('/^[a-z0-9_]+$/', $slug)) {
            $this->error('Invalid provider slug');
            return;
        }

        $provider = Provider::bySlug($slug);
        if ($provider === null) {
            $this->error('Unknown provider');
            return;
        }

        $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        Provider::setEnabled($slug, $enabled);

        $this->success(
            $provider['name'] . ($enabled ? ' enabled' : ' disabled'),
            ['slug' => $slug, 'enabled' => $enabled]
        );
    }

    /** How many users have each provider connected, keyed by slug. */
    private function connectionCounts(): array {
        try {
            $stmt = Database::getConnection()->query(
                "SELECT provider, COUNT(*) AS n FROM oauth_token GROUP BY provider"
            );
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[$row['provider']] = (int) $row['n'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
