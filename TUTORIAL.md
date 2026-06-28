# Tutorial — build a working admin from scratch

This walks you from an empty Laravel app to a **real, working admin**: a small product catalog with
categories, products, image uploads, a status field, roles & permissions, and a custom action. Every step
says *what the command does* and *what you get*. By the end you'll understand the whole loop and can build any
resource yourself.

> Already installed? Skip to [Step 3](#step-3--your-first-resource-categories).
> Just want the 2-minute version? See the [Quickstart](README.md#quickstart).

---

## What you'll build

A `/admin` area with:

- a **login** and a **dashboard**,
- **Categories** — a simple CRUD list (create / edit / delete / search / export),
- **Products** — each belongs to a category, has an image, a price, and a draft/active status,
- **roles & permissions** — an admin who can do everything, plus a "Staff" role you control,
- a custom **"Mark active"** button on the products list.

Nothing here is hand-written CRUD — you'll *generate* it and it just works.

---

## Before you start

- A Laravel app — new (`laravel new shop`) or an existing one.
- **PHP 8.3+** and a database set in `.env`. SQLite is easiest: set `DB_CONNECTION=sqlite` and create an empty
  file `database/database.sqlite`.
- **Node + npm** — used once to compile the admin theme's CSS/JS.

---

## Step 1 — Install the package

```bash
composer require ngos/admin-core
php artisan admin-core:install --access --build --seed
```

What just happened:

- `--access` scaffolded a **login** plus the **Users / Roles / Permissions** screens.
- `--build` installed the front-end packages and compiled the theme.
- `--seed` created an **admin user** and granted it every permission.

> If `--build` fails because Node isn't ready, just run `npm install && npm run build` yourself afterwards.

---

## Step 2 — Log in

Serve the app and open **`/login`**:

```bash
php artisan serve
```

Sign in with the seeded admin:

```
email:     admin@example.com
password:  password
```

You're now at **`/admin`** — a dashboard, with Users / Roles / Permissions in the sidebar. That's the whole
authenticated shell, generated for you.

---

## Step 3 — Your first resource (Categories)

One command generates a **complete CRUD** for a "Category":

```bash
php artisan admin-core:make Category --migration --fields="name:string^, description:text?"
php artisan migrate
```

Read the field spec as: `name` is a required string that must be **unique** (the `^`); `description` is
**optional** text (the `?`).

That one command generated **everything**: a model, a migration, a controller, a service, form-request
validation, the list / create / edit / show views, the routes, a **sidebar menu item**, and the four
permissions (`list-category`, `create-category`, `edit-category`, `delete-category`) — already granted to your
admin role.

Refresh the admin: there's a **Categories** menu item. Open it — a searchable, sortable list with **Add New**,
edit, delete, and CSV export. Create a couple of categories (say "Drinks" and "Snacks"); you'll use them in the
next step.

---

## Step 4 — A richer resource (Products)

Now a resource that uses a **relation**, an **image**, an **enum**, and a **boolean** — all from the field spec:

```bash
php artisan admin-core:make Product --migration \
    --fields="name:string, category_id:foreign:categories, price:decimal, image:image?, status:enum:draft|active, in_stock:boolean"
php artisan migrate
```

Reading the spec:

- `category_id:foreign:categories` — a **belongs-to** relation to the `categories` table. The form renders a
  **searchable dropdown** that pages results from the server, so it scales to thousands of rows.
- `image:image?` — an optional image **upload** (stored and auto-compressed).
- `status:enum:draft|active` — a fixed set of values. The list gets **filter tabs** (All / Draft / Active) and
  a coloured **status badge**; the form gets a dropdown. A PHP enum class is generated for you.
- `price:decimal` and `in_stock:boolean` — a money-style number and an on/off toggle.

Open **Products → Add New**: the category dropdown, the image picker, the status select, and the toggle are all
wired. Create a product under one of your categories — done.

> Curious what else you can put in `--fields`? Run `php artisan admin-core:make --list-fields` for the full
> catalog (richtext, json, date, slug, many-to-many, media galleries, and more).

---

## Step 5 — Roles & permissions

Every resource you generate gets `list / create / edit / delete` permissions, all granted to the **admin**
role — that's why you can already do everything.

To give a teammate *less* access:

1. **Roles → Add New** → create a "Staff" role.
2. Tick only what they should have — e.g. `list-product`, `create-product`, `edit-product`, but **not**
   `delete-product`, and nothing for categories.
3. **Users → Add New** → create a user and assign the **Staff** role.

When that user logs in, the UI reflects exactly what they're allowed to do: the **Delete** button and the
**Categories** menu simply won't appear — and the routes are blocked on the server too, not just hidden.

---

## Step 6 — A custom action

Suppose you want a **"Mark active"** button that flips selected products to `active` in one click. Open the
generated `app/Http/Controllers/Backend/ProductController.php` and add a `resourceActions()` method:

```php
use Ngos\AdminCore\Actions\Action;

protected function resourceActions(): array
{
    return [
        Action::make('mark-active')->label('Mark active')->icon('bi bi-check2-circle')
            ->color('success')->confirm()
            ->handle(fn ($records) => $records->each->update(['status' => 'active'])),
    ];
}
```

Grant the `mark-active-product` permission (add it in the Permissions screen, or to your seeder). Now the
products list shows a **Mark active** button when you select rows, plus a **Mark active** item in each row's
menu: select rows → click → confirm → done.

> Want it to need a manager's sign-off first? Add `->requiresApproval()` to the action and the request lands in
> the [Approvals inbox](README.md#approval-workflow) instead of running immediately.

---

## You've got the whole loop

You now know the core workflow — **`admin-core:make` → `migrate` → it's live** — plus how relations, uploads,
enums, permissions, and custom actions fit together. Build the next resource exactly the same way.

Where to go next:

- **More field types** — `php artisan admin-core:make --list-fields`, or the
  [field reference](README.md#generating-fields-too---fields).
- **Import / export, soft-deletes, audit trail, drag-reorder** — the
  [list features](README.md#every-list-comes-with-export-import--bulk-delete).
- **A JSON API** for the same resource — [`--api`](README.md#json-api---api).
- **Media library, dashboard widgets, approvals, multi-portal** — see the [README Contents](README.md#contents).
