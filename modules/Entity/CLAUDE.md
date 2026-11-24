# Entity module

An admin-only module. It has no public controller (no `Entity.php`); it offers the entity
screens to the Admin module through `Admin/`.

| URL | Method | |
|-----|--------|--|
| `/admin/entity` | `EntityAdmin::index()` | Entity types as cards, Create entity |
| `/admin/entity/manage/{label}` | `manage()` | Records of one entity type; POST creates a record |
| `/admin/entity/create` | `create()` | GET form, POST defines a new entity type |
| `/admin/entity/update` | `update()` | POST, inline edit of one attribute value |
| `/admin/entity/delete` | `delete()` | POST, deletes records of one type |

- `model/EntityStore.php` (`Zero\Core\Module\Entity\Model\EntityStore`) reads the EAV tables
  (`entity_type`, `entity`) through the PDO it is given. Its namespace is `Zero\Core\Module\Entity`,
  distinct from the entity framework's `Zero\Entity` in `entity/`.
- The event log is not here: it is the Admin module's own Audit Log screen, `/admin/audit`.
- `index()` shows "The entity tables are not installed on this site." when `entity_type` does
  not exist.
- `update` and `delete` accept POST only and require their parameters; every offering route
  sits behind `allow.admin`.
- Assets are flat in `Admin/assets/` (`create.*`, `manage.*`) and inlined by the views through
  `loadInlineAsset()`.

Tests: `vendor/bin/phpunit -c phpunit.xml core/modules/Entity/tests`.
