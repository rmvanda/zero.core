<?php
// Permissions::index() loads every capability with its holder counts.
/** @var array<int,array{key:string,url:string,users:int,groups:int}> $rows */
$rows = $this->capabilityRows;
?>

<div class="content-wrapper">
    <h1>Permissions</h1>
    <p>Every capability a module asks for or somebody holds. Open one to see who holds it and to grant or revoke it for several users and groups at once.</p>

    <?php if (empty($rows)): ?>
        <div class="admin-info-box text-center">No capability is declared or held yet.</div>
    <?php else: ?>
        <table class="admin-table permissions-table">
            <thead>
                <tr>
                    <th>Capability</th>
                    <th>Users granted it</th>
                    <th>Groups granted it</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($row['url']) ?>"><code><?= htmlspecialchars($row['key']) ?></code></a></td>
                        <td><?= (int) $row['users'] ?></td>
                        <td><?= (int) $row['groups'] ?></td>
                        <td class="actions-cell"><a href="<?= htmlspecialchars($row['url']) ?>" class="btn-small">Holders</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
