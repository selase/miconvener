# Tenant-Scoped Roles and Permissions

## Problem

System roles (Superadmin, Org Superadmin, Org Admin) are defined globally by the SaaS superadmin. Tenants can create custom roles from scratch, but cannot easily derive a new role from an existing system role. Tenants with different workflows (legal firms, agencies, healthcare orgs) need the flexibility to define role structures that match their operations while still benefiting from platform-managed defaults.

## Design Decision

**Approach: Duplicate-on-demand.** System roles are immutable for tenants. Tenants who want a modified version duplicate the system role, rename it, adjust permissions, and assign it to their users. This avoids override tracking, merge conflicts on platform updates, and runtime permission resolution complexity.

## Data Model

One nullable column added to the existing `roles` table:

```
cloned_from_role_id  BIGINT UNSIGNED, nullable, FK -> roles.id (SET NULL on delete)
```

No new tables. No changes to `permissions`, `model_has_roles`, `model_has_permissions`, `tenant_user`, or Spatie config.

**SET NULL on delete rationale:** If a superadmin deletes a system role, cloned tenant roles continue to function independently. They lose lineage metadata only.

## Role Taxonomy

### System Roles (tenant_id = null)

- Defined by SaaS superadmin (currently: Superadmin, Org Superadmin, Org Admin)
- Visible to all tenants as read-only
- Tenants see a "Duplicate" action, no edit/delete
- When superadmin updates a system role, all tenants immediately see the change on the original

### Custom Tenant Roles (tenant_id = tenant.id)

- Created from scratch OR by duplicating a system role
- Fully editable by tenant Org Superadmin
- Permissions limited to: `Permission::TENANT_SAFE` intersected with tenant's entitled permissions (via `EntitlementService`)
- Cannot be deleted while users are assigned (blocked with user count message)
- Unlimited creation (no package-based caps) -- gated only by available TENANT_SAFE permissions

## Duplication Flow

1. Tenant clicks "Duplicate" on a system role
2. Modal/form pre-fills with name "{Original Name} (Custom)" and source role's permissions (filtered to TENANT_SAFE + tenant entitlements)
3. Tenant renames and adjusts permissions
4. Save creates a new tenant-scoped role with `cloned_from_role_id` set to the source
5. Users are NOT auto-reassigned -- tenant must manually assign users to the new role

## Service Layer

### RoleDuplicationService

Single method:

```php
duplicateRole(Role $source, Tenant $tenant, string $newName, array $permissions): Role
```

- Wraps in a DB transaction on `landlord` connection
- Creates new role with `tenant_id = tenant.id`, `cloned_from_role_id = source.id`
- Syncs only permissions that pass two gates: in `Permission::TENANT_SAFE` AND tenant is entitled via `EntitlementService`
- Returns the new Role instance

### Duplicate Validation (Form Request)

- `name`: required, string, max 255, unique within `roles` where `tenant_id = current tenant` and `guard_name = web`
- `permissions`: required, array, each must exist in `permissions` table AND be in TENANT_SAFE AND be entitled
- `source_role_id`: required, exists in `roles`, must be a system role (tenant_id = null)

### Tenant RoleController Changes

- `duplicate(Request $request, Role $role)` -- new action, validates via DuplicateRoleRequest, calls RoleDuplicationService
- `destroy(Role $role)` -- add guard: if role has assigned users, return 422 with user count
- `update(Role $role)` -- unchanged (already validates TENANT_SAFE)
- `index()` -- unchanged (already shows system + tenant roles)

### Admin RoleController

No changes. Superadmin already has unrestricted role management.

### No Changes To

- Middleware, gates, or permission check logic
- `setPermissionsTeamId()` scoping (duplicated roles are standard tenant-scoped roles)
- `EntitlementService` (continues to gate which permissions are available)
- Queue tenant context handling

## UI Design

### Role Listing Page (Tenant)

Two sections:

**Platform Roles:**
- Displayed as read-only cards/rows with lock icon
- "Duplicate" button on each
- Permissions shown as read-only tags

**Custom Roles:**
- Full edit/delete actions
- If cloned, subtle label: "Based on {Original Name}"
- Delete button disabled with tooltip when users are assigned: "{X} users assigned -- reassign before deleting"

### Duplicate Modal/Page

- Pre-filled name: "{Original Name} (Custom)"
- All TENANT_SAFE permissions as checkboxes, pre-checked matching source
- Non-entitled permissions greyed out with tooltip: "Upgrade plan to enable"
- Save creates role, success message with link to assign users

### Role Edit Page (Custom Roles Only)

- If `cloned_from_role_id` is set, collapsible "Compare with platform default" section:
  - Added permissions (green)
  - Removed permissions (red)
  - Unchanged (grey)
- Informational only, no sync/merge action

### Deletion Flow

- If users assigned: blocking message with count, no delete possible
- If no users: confirmation dialog, then permanent deletion

## Platform Update Propagation

When the SaaS superadmin updates a system role's permissions:

- All tenants using the original system role see the change immediately
- Tenant-cloned copies are unaffected (they are independent snapshots)
- The "Compare with platform default" diff on cloned roles automatically reflects the divergence
- No notifications, no merge -- clean separation of ownership

## Restoring Defaults

A tenant who wants to return to platform defaults:

1. Reassigns users from the custom role to the original system role
2. Deletes the custom role
3. No special "reset" mechanism needed -- the original system role is always available and current

## Testing Strategy

### Unit Tests

- `RoleDuplicationService`: cloning copies correct permissions, respects TENANT_SAFE filter, respects entitlement gate, sets `cloned_from_role_id`, scopes to tenant
- Role deletion guard: blocked when users assigned, allowed when no users

### Feature Tests

- Tenant duplicates a system role -> new role with correct tenant_id and lineage
- Tenant edits custom role permissions -> only TENANT_SAFE allowed
- Tenant tries to delete role with assigned users -> 422 with user count
- Tenant deletes role with no users -> success
- Tenant tries to edit/delete a system role -> 403
- Superadmin updates system role -> tenant clones unaffected, original updated
- Tenant creates role from scratch -> existing flow unchanged
