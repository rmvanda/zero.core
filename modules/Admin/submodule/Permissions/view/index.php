<?php
// Permissions::index() loads every capability with its holder counts.
/** @var array<int,array{key:string,url:string,users:int,groups:int}> $rows */
$rows = $this->capabilityRows;
?>

<div class="content-wrapper">
    <header class="admin-head">
        <div>
            <h1>Permissions</h1>
            <p class="admin-lede">Every capability a module asks for or somebody holds. Open one to see who holds it and to grant or revoke it for several users and groups at once.</p>
        </div>
    </header>

    <?php if (empty($rows)): ?>
        <div class="admin-empty">
            <span class="material-symbols-outlined" aria-hidden="true">key</span>
            <p>No capability is declared or held yet. Grant one from a user's screen and it is listed here.</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
        <table class="admin-table permissions-table">
            <thead>
                <tr>
                    <th>Capability</th>
                    <th class="num">Users granted it</th>
                    <th class="num">Groups granted it</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($row['url']) ?>" class="permission-key-link"><code><?= htmlspecialchars($row['key']) ?></code></a></td>
                        <td class="num<?= (int) $row['users'] === 0 ? ' dim' : '' ?>"><?= (int) $row['users'] ?></td>
                        <td class="num<?= (int) $row['groups'] === 0 ? ' dim' : '' ?>"><?= (int) $row['groups'] ?></td>
                        <td class="actions-cell"><a href="<?= htmlspecialchars($row['url']) ?>" class="btn-small">Holders</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
