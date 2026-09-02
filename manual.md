# Comprehensive Project Manual

## Introduction

Welcome to the Multi-Tenant SaaS Starter Kit. This documentation serves as the primary source of truth for the kit's architecture, features, and usage patterns. It is designed to guide developers of all levels (Junior to Senior) and stakeholders in understanding, setting up, and utilizing the system.

This starter kit is built on **Laravel 12** and **PHP 8.4**, providing a robust foundation for building scalable, secure, and multi-tenant SaaS applications. It incorporates best practices for tenancy isolation, role-based access control (RBAC), feature flagging, and secure secrets management.

## High-Level Overview

The system is architected around a **Landlord/Tenant** model, separating the control plane (Landlord) from the data plane (Tenant).

### Core Capabilities

*   **Multi-Tenancy:** Supports multiple database isolation strategies (Shared, Dedicated, Bring Your Own).
*   **Tenant Resolution:** Automatically identifies the active tenant based on request context (Session, Header, Route).
*   **Security:** Features opt-in encryption-at-rest, field-level encryption, and strict tenant data isolation.
*   **RBAC:** Granular role and permission management scoped to specific tenants.
*   **Feature Flags:** Dynamic feature toggling per tenant without code deployment.
*   **Observability:** Built-in health checks, audit logging, and tenant-aware system logs.

---

## 1. Getting Started

This section will guide you through setting up your development environment and getting the Multi-Tenant SaaS Starter Kit running locally.

### 1.1 Prerequisites

Before you begin, ensure you have the following installed on your system:

*   **PHP 8.4+:** The project leverages features available in PHP 8.4 and later.
    *   **Required PHP Extensions:** `pdo_mysql` (for MySQL), `pdo_pgsql` (for PostgreSQL), `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pcntl`, `tokenizer`, `xml`.
*   **Composer:** A dependency manager for PHP.
*   **Node.js & npm/yarn:** For frontend asset compilation (required for Vite).
*   **Database Server:**
    *   **MySQL 8+** (recommended for local development)
    *   **PostgreSQL 13+** (fully supported)
*   **Optional - Docker/Sail:** While not strictly required, Docker and Laravel Sail provide a convenient and consistent development environment. This manual assumes a traditional local PHP setup, but the project is Sail-ready.

### 1.2 Installation

Follow these steps to get the project up and running:

1.  **Clone the Repository:**
    ```bash
    git clone <repository-url>
    cd starterkit-v2 # Or whatever your project folder is named
    ```

2.  **Install PHP Dependencies:**
    ```bash
    composer install
    ```

3.  **Install Node Dependencies:**
    ```bash
    npm install # or yarn install
    ```

4.  **Create Environment File:**
    ```bash
    cp .env.example .env
    ```

5.  **Generate Application Key:**
    ```bash
    php artisan key:generate
    ```

6.  **Configure Database:**
    Open the newly created `.env` file and update the database connection details.
    ```dotenv
    DB_CONNECTION=mysql # or pgsql
    DB_HOST=127.0.0.1
    DB_PORT=3306 # or 5432 for PostgreSQL
    DB_DATABASE=your_database_name
    DB_USERNAME=your_database_user
    DB_PASSWORD=your_database_password
    ```
    *   **Note:** This starter kit utilizes a "Landlord" database to manage tenants. The initial setup will create this database. Tenant-specific databases will be configured later.

7.  **Run Migrations:**
    This command will run all landlord-specific migrations, setting up the core tables for tenant management, users, roles, etc.
    ```bash
    php artisan migrate
    ```

8.  **Seed the Database (Optional but Recommended):**
    This will populate your landlord database with initial data, including an admin user.
    ```bash
    php artisan db:seed
    ```

9.  **Link Storage:**
    ```bash
    php artisan storage:link
    ```

10. **Start Development Server:**
    ```bash
    php artisan serve
    npm run dev # for Vite hot-reloading of frontend assets
    ```
    You should now be able to access the application at `http://127.0.0.1:8000` (or `http://localhost:8000`).

### 1.3 Setting Up a New Project Based on This Starter Kit

To use this starter kit as the foundation for a new application, follow these general guidelines:

1.  **Follow Installation Steps:** Complete steps 1-9 from the "Installation" section above.
2.  **Customize Application Name:** Update the `APP_NAME` in your `.env` file and potentially run `php artisan app:name "YourAppName"`.
3.  **Remove Existing Commit History (Optional):** If you're starting a fresh project, you might want to remove the `.git` directory and re-initialize a new Git repository.
    ```bash
    rm -rf .git
    git init
    git add .
    git commit -m "Initial commit from Multi-Tenant SaaS Starter Kit"
    ```
4.  **Begin Development:** Start building your application features, leveraging the multi-tenancy and other capabilities provided by the kit.

---

## 2. Core Architecture

### 2.1 Landlord vs. Tenant: The Dual Plane Architecture

The Multi-Tenant SaaS Starter Kit employs a **dual-plane architecture** that clearly separates the "Landlord" (control) plane from the "Tenant" (data) plane. Understanding this distinction is fundamental to working with the system.

#### The Landlord Plane (Control Plane)

The Landlord plane is responsible for managing the overarching SaaS platform. This includes:

*   **Platform-wide Configuration:** Settings that apply to all tenants.
*   **Tenant Management:** Creating, updating, and deleting tenants. This is where tenant metadata (e.g., tenant ID, database connection details, subscription status) is stored.
*   **User Authentication (Platform Level):** Managing users who can access and switch between multiple tenants.
*   **Shared Services:** Any services or data that are shared across all tenants (e.g., global feature flags definitions, audit logs of tenant switches).

**Implementation Details:**

*   **Database:** The Landlord plane operates on the default database connection (typically named `mysql` or `pgsql` in `config/database.php`). All tables prefixed with `landlord_` in the `database/migrations/landlord` directory belong to this plane.
*   **Models:** Landlord-specific models (e.g., `App\Models\Tenant`, `App\Models\User`) interact primarily with the Landlord database.
*   **Codebase:** Found in various parts of the application, particularly in `app/Services/Tenancy` and relevant `app/Http/Controllers` for tenant management.

#### The Tenant Plane (Data Plane)

The Tenant plane is where the actual customer data and application logic for individual tenants reside. Each tenant operates in its own isolated environment, ensuring data separation and security.

*   **Tenant-Specific Data:** All data belonging to a particular customer (e.g., their users, products, orders, configurations).
*   **Application Logic:** Features and modules that operate on the tenant's data.
*   **Tenant-Scoped Users:** Users managed within the context of a specific tenant, often with their own roles and permissions.

**Implementation Details:**

*   **Database:** Each tenant can have its own dedicated database connection or share a database with other tenants while maintaining logical isolation. The system dynamically configures the `tenant` database connection based on the active tenant.
*   **Models:** Tenant-aware models (those using the `App\Traits\BelongsToTenant` trait) automatically scope queries to the currently active tenant's data.
*   **Middleware:** The `App\Http\Middleware\ResolveTenant` middleware is critical for identifying the active tenant for each request and configuring the appropriate tenant database connection.

#### Connection Switching

The magic of multi-tenancy in this kit largely relies on the ability to dynamically switch database connections based on the resolved tenant context.

*   **`ResolveTenant` Middleware:** This middleware runs on every incoming request. It determines the active tenant by checking the session, `X-Tenant` header, or route parameters. Once the tenant is identified, it calls upon the `TenantDatabaseManager` to configure the `tenant` database connection.
*   **`TenantDatabaseManager`:** This service is responsible for creating and configuring the Laravel database connection for the active tenant. It uses the `SecretsProvider` to securely retrieve database credentials for the tenant, allowing for flexible database configurations (e.g., different hosts, usernames, passwords for each tenant).
*   **`BelongsToTenant` Trait:** Eloquent models that should be tenant-scoped utilize this trait. It automatically applies a global scope to all queries, ensuring that only data belonging to the active tenant is retrieved or modified. This prevents accidental cross-tenant data leakage.

By separating these concerns into distinct planes and implementing dynamic connection switching, the starter kit provides a robust and secure foundation for multi-tenant applications.

### 2.2 Tenant Database Isolation Tiers

The Multi-Tenant SaaS Starter Kit supports various database isolation strategies, allowing you to choose the model that best fits your application's requirements for security, scalability, and cost-effectiveness. These tiers are managed dynamically by the `TenantDatabaseManager` and rely on the `SecretsProvider` for secure credential retrieval.

#### Tier 1: Shared Database, Shared Schema

In this model, all tenants share a single database and schema. Tenant data is isolated at the application level by including a `tenant_id` column in relevant tables and enforcing queries through global scopes (e.g., using the `BelongsToTenant` trait).

*   **Pros:**
    *   **Cost-effective:** Lower database infrastructure costs.
    *   **Easier Management:** Simpler backup and restore processes for the entire dataset.
    *   **Cross-Tenant Analytics:** Easier to perform analytics across all tenants if needed.
*   **Cons:**
    *   **Security Risk:** Higher risk of data leakage if application-level isolation fails.
    *   **Performance:** Can suffer from "noisy neighbor" syndrome as tenants compete for resources.
    *   **Scalability:** Vertical scaling limitations for very large multi-tenant applications.
    *   **Compliance:** May not meet strict compliance requirements (e.g., HIPAA, GDPR) that demand physical data separation.

**Configuration Example (`.env` or `secrets.json` for tenant):**

```json
{
  "DB_CONNECTION": "mysql",
  "DB_HOST": "127.0.0.1",
  "DB_PORT": "3306",
  "DB_DATABASE": "shared_tenant_db",
  "DB_USERNAME": "shared_user",
  "DB_PASSWORD": "shared_password"
}
```
*   **Note:** In this tier, each tenant will point to the same database credentials. The `TenantScope` (applied via `BelongsToTenant` trait) ensures data isolation.

#### Tier 2: Dedicated Database, Separate Schema (Same Server)

Each tenant has its own dedicated database on the same database server. This provides better isolation than a shared schema, as each tenant's data resides in its own logical container.

*   **Pros:**
    *   **Improved Isolation:** Reduces the risk of cross-tenant data leakage.
    *   **Easier Backup/Restore:** Can backup/restore individual tenant databases.
    *   **Better Performance:** Reduces "noisy neighbor" issues compared to shared schema.
    *   **Resource Contention:** Still subject to overall server resource limits.
    *   **Operational Overhead:** More databases to manage.

**Configuration Example (`.env` or `secrets.json` for tenant):**

```json
{
  "DB_CONNECTION": "mysql",
  "DB_HOST": "127.0.0.1",
  "DB_PORT": "3306",
  "DB_DATABASE": "tenant_db_<tenant_id>",
  "DB_USERNAME": "tenant_user_<tenant_id>",
  "DB_PASSWORD": "tenant_password_<tenant_id>"
}
```
*   **Note:** Each tenant will have unique database names and potentially unique credentials.

#### Tier 3: Dedicated Database, Separate Server (Bring Your Own - BYO)

This is the highest level of isolation, where each tenant operates on its own dedicated database server (or even a fully separate infrastructure). This is often required for enterprise clients with strict security, compliance, or performance requirements.

*   **Pros:**
    *   **Maximum Isolation:** Complete physical separation of data and resources.
    *   **Highest Security:** Ideal for strict compliance (HIPAA, GDPR, etc.).
    *   **Optimal Performance:** No "noisy neighbor" issues; resources are fully dedicated.
    *   **Scalability:** Each tenant can scale independently.
*   **Cons:**
    *   **Highest Cost:** Significant infrastructure and operational costs.
    *   **Complex Management:** Requires robust automation for provisioning, monitoring, and maintenance.

**Configuration Example (`.env` or `secrets.json` for tenant):**

```json
{
  "DB_CONNECTION": "mysql",
  "DB_HOST": "tenant_db_server_<tenant_id>",
  "DB_PORT": "3306",
  "DB_DATABASE": "tenant_production",
  "DB_USERNAME": "tenant_root",
  "DB_PASSWORD": "super_secret_password"
}
```
*   **Note:** Each tenant will have entirely different connection parameters, potentially pointing to different cloud providers or physical servers.

The `TenantDatabaseManager` intelligently handles these configurations, ensuring that the correct database connection is established for the active tenant, regardless of the chosen isolation tier. This flexibility allows the SaaS platform to cater to a wide range of customer needs and compliance requirements.

---

## 3. Feature Guides and Examples

### 3.1 Security & RBAC Guide

The Multi-Tenant SaaS Starter Kit integrates `spatie/laravel-permission` to provide robust Role-Based Access Control (RBAC). A key aspect of this implementation is its **tenant-scoped nature**, ensuring that roles and permissions are isolated and managed within the context of each individual tenant. This prevents cross-tenant permission leakage and enhances security.

#### Tenant-Scoped Roles and Permissions (Teams)

The `spatie/laravel-permission` package supports a `teams` feature, which is leveraged here to implement tenant scoping. Each tenant is treated as a "team," and roles/permissions are assigned within the boundaries of that team.

*   **`tenant_id` as Team Identifier:** The `tenant_id` column on the `roles` and `permissions` tables (and their pivot tables) acts as the team identifier. When a user is in a tenant's context, only the roles and permissions associated with that tenant's `tenant_id` are considered.
*   **`App\Models\Role` and `App\Models\Permission`:** These models are extended to automatically apply a global scope based on the active tenant, similar to how other tenant-scoped models behave. This means that when you query for roles or permissions, you will only see those relevant to the current tenant.

#### Assigning Roles to Users

Users can be assigned roles within a specific tenant. This allows for fine-grained control over what actions a user can perform in each tenant they belong to.

**Example: Assigning a 'tenant_admin' role to a user within a tenant context:**

```php
use App\Models\User;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext; // Assuming TenantContext for setting active tenant

// 1. Resolve the tenant context
$tenant = Tenant::find('tenant_uuid_123');
(new TenantContext())->setTenant($tenant); // Set the active tenant

// 2. Find the user (who belongs to this tenant)
$user = User::find(1);

// 3. Assign a role within this tenant's scope
$user->assignRole('tenant_admin');
// The 'tenant_admin' role will be created/assigned with the current tenant's ID.
```
*   **Note:** If the role 'tenant_admin' doesn't exist for `tenant_uuid_123`, `spatie/laravel-permission` will create it when `assignRole` is called, associating it with the current tenant's ID.

#### Checking Permissions

Checking if a user has a specific permission also respects the tenant scope.

**Example: Checking for a 'manage_users' permission:**

```php
use App\Models\User;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;

// 1. Resolve the tenant context
$tenant = Tenant::find('tenant_uuid_123');
(new TenantContext())->setTenant($tenant);

// 2. Find the user
$user = User::find(1);

// 3. Check for permission within the active tenant's scope
if ($user->can('manage_users')) {
    // User has 'manage_users' permission for 'tenant_uuid_123'
    echo "User can manage users in this tenant.";
} else {
    echo "User cannot manage users in this tenant.";
}

// Without setting the tenant context, the check would fail or behave unexpectedly.
```

#### Defining Roles and Permissions

Roles and permissions are defined and managed as usual with `spatie/laravel-permission`, but with the understanding that they will be scoped by the active tenant. You can create roles and permissions explicitly, and they will be associated with the currently active tenant.

**Example: Creating a new permission for a tenant:**

```php
use Spatie\Permission\Models\Permission;
use App\Services\Tenancy\TenantContext;
use App\Models\Tenant;

$tenant = Tenant::find('tenant_uuid_123');
(new TenantContext())->setTenant($tenant);

Permission::create(['name' => 'edit_settings']);
// This permission is now associated with 'tenant_uuid_123'.
```

#### Policies for Granular Authorization

Laravel Policies (`app/Policies`) can be used in conjunction with RBAC for more granular authorization logic. The base `App\Policies\BasePolicy` provides a good starting point for tenant-aware policies.

### 3.3 Storage & Background Jobs

#### Tenant-Aware File Storage

In a multi-tenant application, it's crucial to ensure that files uploaded by one tenant are not accessible by another. This starter kit provides a `TenantStorageManager` to handle tenant-aware file storage.

*   **`TenantStorageManager`:** This service (`App\Services\Tenancy\TenantStorageManager`) dynamically configures the `tenant` filesystem disk based on the active tenant. It supports two primary modes of isolation for Amazon S3:
    1.  **Shared Bucket with Prefixes:** All tenants share a single S3 bucket, but their files are stored under a unique prefix (e.g., `s3-bucket-name/<tenant_id>/`). This is the default and most cost-effective approach.
    2.  **Bring Your Own Bucket (BYOB):** For tenants requiring stricter isolation, you can configure a dedicated S3 bucket. The `TenantStorageManager` will dynamically set the bucket name based on the tenant's configuration.

*   **Using the `tenant` Disk:** When working with tenant-specific files, always use the `tenant` disk.

    ```php
    use Illuminate\Support\Facades\Storage;

    // Store a file for the active tenant
    Storage::disk('tenant')->put('avatars/user-1.jpg', $fileContent);

    // Retrieve a file's URL
    $url = Storage::disk('tenant')->url('avatars/user-1.jpg');
    ```

#### Tenant-Aware Background Jobs

Background jobs often need to operate within the context of a specific tenant. For example, a job that generates a report must only access the data for the tenant who requested it. This starter kit provides a `TenantAware` trait and middleware to make your jobs tenant-aware.

*   **`TenantAware` Trait:** By using the `App\Traits\TenantAware` trait in your job, you ensure that the `tenant_id` of the active tenant is automatically stored in the job's payload when it is dispatched.

*   **`TenantAwareJob` Middleware:** This middleware (`App\Jobs\Middleware\TenantAwareJob`) is automatically applied to all jobs that use the `TenantAware` trait. When the job is processed by a queue worker, this middleware:
    1.  Retrieves the `tenant_id` from the job's payload.
    2.  Resolves the corresponding tenant from the landlord database.
    3.  Sets the tenant context using the `TenantContext` service.
    4.  Configures the tenant's database and filesystem connections.

This ensures that your job runs in the exact same tenant context as when it was dispatched, preventing data leakage and ensuring correct data access.

**Example of a Tenant-Aware Job:**

```php
namespace App\Jobs;

use App\Traits\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateTenantReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function handle()
    {
        // The TenantAwareJob middleware has already set the tenant context.
        // You can now safely access tenant-specific data and services.

        // Example: Get all users for the current tenant
        $users = \App\Models\User::all(); // This will be scoped to the tenant

        // Generate the report...
    }
}
```

**Dispatching the Job:**

```php
// In a controller or service where the tenant context is already resolved...
GenerateTenantReport::dispatch();
// The TenantAware trait will automatically add the tenant_id to the payload.
```

This robust system for handling tenant-aware storage and background jobs is critical for building a secure and scalable multi-tenant SaaS application.

---

## 4. Operational Excellence

### 4.1 Migrations & Secrets

#### Tenant-Aware Migrations (`tenants:migrate`)

Managing database schemas across multiple tenants can be complex. The starter kit simplifies this with a custom Artisan command: `tenants:migrate`. This command allows you to run migrations across all (or a subset) of your tenants' databases.

*   **How it works:**
    1.  The command iterates through each registered tenant in your Landlord database.
    2.  For each tenant, it dynamically switches the database connection to the tenant's dedicated database (or configures the `tenant` connection for shared database strategies).
    3.  It then runs the specified migrations against that tenant's database.

*   **Failure Guardrails:**
    *   **Atomic Operations:** Ensure your migrations are atomic. If a migration fails for one tenant, it should ideally not affect other tenants, and the failed tenant's database should remain in a consistent state (e.g., using transactions within your migration).
    *   **Rollback Strategy:** Plan for a rollback strategy. The `tenants:rollback` command can be used to revert migrations for tenants.
    *   **Monitoring:** Monitor the `tenants:migrate` command's output and integrate it with your CI/CD pipelines to catch failures early.

**Usage:**

```bash
# Migrate all tenants
php artisan tenants:migrate

# Migrate a specific tenant (by UUID)
php artisan tenants:migrate --tenant=your-tenant-uuid

# Rollback migrations for all tenants
php artisan tenants:rollback

# Refresh migrations (drops all tables and re-runs them) for all tenants
php artisan tenants:migrate:fresh
```
*   **Note:** By default, `tenants:migrate` will run migrations located in `database/migrations/tenant`. You can specify other paths if needed.

#### `SecretsProvider` Abstraction

Securely managing credentials and sensitive configuration for each tenant is paramount. The `SecretsProvider` abstraction (`App\Contracts\Secrets\SecretsProvider`) ensures that tenant-specific secrets (like database credentials, API keys, etc.) are retrieved securely and on-demand.

*   **Purpose:**
    *   **Centralized Secret Management:** Provides a unified interface for accessing secrets.
    *   **Environment Agnostic:** Allows you to swap out the underlying secret storage mechanism (e.g., AWS Secrets Manager, HashiCorp Vault, environment variables) without changing application code.
    *   **Dynamic Retrieval:** Secrets are fetched only when needed for a specific tenant, reducing the attack surface.

*   **Implementation:**
    *   The `SecretsProvider` interface defines methods for `getSecret` and `setSecret`.
    *   A default implementation (e.g., `App\Services\Secrets\FileSecretsProvider`) might store secrets in encrypted files, while production environments could use a cloud-native secret store.
    *   The `TenantDatabaseManager` uses the `SecretsProvider` to fetch database credentials for tenants.

*   **Example (Conceptual):**

    ```php
    // In your TenantDatabaseManager
    $secretsProvider = app(SecretsProvider::class);
    $dbCredentials = $secretsProvider->getSecret("tenant_{$tenant->uuid}_db_credentials");

    // Example of a secrets.json structure (managed by a FileSecretsProvider)
    // /secrets/tenant_<tenant_uuid>_db_credentials.json
    {
      "DB_CONNECTION": "mysql",
      "DB_HOST": "tenant_db_server",
      "DB_PORT": "3306",
      "DB_DATABASE": "tenant_db",
      "DB_USERNAME": "tenant_user",
      "DB_PASSWORD": "super_secret_password"
    }
    ```

By leveraging `tenants:migrate` and the `SecretsProvider`, the starter kit promotes operational efficiency and robust security practices for multi-tenant deployments.

---

### 4.2 Observability & Troubleshooting

#### Health Checks and Log Context (`tenant_id`)

In a multi-tenant environment, effective observability is critical for monitoring the health of your application and diagnosing issues quickly. The starter kit is designed with tenant-aware logging and health checks to facilitate this.

*   **Health Checks:**
    *   The application includes health endpoints (e.g., `/health`) that can be used by load balancers, container orchestration systems (like Kubernetes), and monitoring tools to determine the application's availability and responsiveness.
    *   These health checks can be extended to include tenant-specific checks (e.g., verifying database connectivity for a critical subset of tenants, checking S3 access for tenant buckets).
    *   Refer to `config/health.php` and `App\Http\Controllers\HealthCheckController` for configuration and implementation details.

*   **Tenant-Aware Logging:**
    *   All application logs (Laravel's default logger, Monolog) are automatically enriched with the `tenant_id` of the currently active tenant. This is achieved through a custom logging processor.
    *   This ensures that when you're analyzing logs, you can easily filter and identify issues related to specific tenants, greatly simplifying troubleshooting.
    *   When the tenant context is not resolved (e.g., in Landlord-specific operations or console commands), the `tenant_id` will be `null` or `N/A`.

**Example Log Entry (conceptual):**

```json
{
    "message": "User failed to update profile.",
    "context": {
        "user_id": 123,
        "tenant_id": "tenant_uuid_456",
        "request_id": "xyz-789",
        "exception": "..."
    },
    "level": "ERROR",
    "datetime": "2026-01-15T12:30:00Z"
}
```

#### FAQ/Troubleshooting

This section addresses common issues and provides guidance on troubleshooting specific multi-tenant scenarios.

*   **Q: Why are my queries returning no data, even though I know data exists?**
    *   **A:** Ensure the tenant context is correctly set. Check if the `ResolveTenant` middleware is active for the route you're accessing. If you're running a console command, remember that tenant context needs to be explicitly set (e.g., using `php artisan tenants:command --tenant=uuid`). Also, verify that your models are using the `BelongsToTenant` trait if they are meant to be tenant-scoped.

*   **Q: My tenant-specific S3 uploads are failing or not appearing in the correct location.**
    *   **A:** Verify that the `tenant` disk is being used for storage operations. Ensure the `TenantStorageManager` is correctly configured and that the S3 bucket and credentials (via `SecretsProvider`) are valid for the active tenant. Check the S3 bucket's permissions and your AWS credentials.

*   **Q: Roles and permissions aren't working as expected for my tenant users.**
    *   **A:** Confirm that the `tenant_id` is being correctly passed and set in the Spatie Permission configuration. Ensure that roles and permissions are assigned within the correct tenant context. Check the `model_has_roles` and `role_has_permissions` tables for the `tenant_id` column.

*   **Q: My background jobs are failing or processing data for the wrong tenant.**
    *   **A:** Confirm that your job classes are using the `App\Traits\TenantAware` trait and that the `App\Jobs\Middleware\TenantAwareJob` middleware is applied. This middleware ensures the tenant context is re-established when the job is processed by a queue worker.

*   **Q: How do I add a new tenant?**
    *   **A:** Implement logic in a Landlord-scoped controller or Artisan command. This typically involves:
        1.  Creating a new `Tenant` record in the Landlord database.
        2.  Optionally, creating a new database for the tenant (for dedicated database tiers).
        3.  Running `php artisan tenants:migrate --tenant=new-tenant-uuid` to set up the tenant's schema.
        4.  Seeding initial data for the new tenant.
        5.  Storing tenant-specific secrets (e.g., database credentials) via the `SecretsProvider`.

*   **Q: How can I debug tenant-specific issues?**
    *   **A:**
        *   **Logging:** Use tenant-aware logging to filter by `tenant_id`.
        *   **Debugging Tools:** Use tools like Laravel Telescope (if installed and configured for tenant awareness) or Xdebug to step through code execution with the tenant context in mind.
        *   **Artisan Commands:** Utilize `php artisan tinker` with the tenant context manually set:
            ```bash
            php artisan tinker --tenant=your-tenant-uuid
            ```
            Then you can interact with tenant-scoped models and services.

Effective troubleshooting relies on understanding the dual-plane architecture and how tenant context propagates through the application.

---