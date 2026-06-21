<?php

// admin-core UI strings (English). Override per app by publishing with
// `--tag=admin-core-lang`, or add a locale with `php artisan admin-core:translate <code>`.
return [
    'language' => 'Language',

    'actions' => [
        'create' => 'Create',
        'edit' => 'Edit',
        'update' => 'Update',
        'delete' => 'Delete',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'back' => 'Back',
        'search' => 'Search',
        'reset' => 'Reset',
        'confirm' => 'Confirm',
        'view' => 'View',
        'export' => 'Export',
        'import' => 'Import',
        'restore' => 'Restore',
        'delete_permanently' => 'Delete permanently',
        'translate' => 'Translate',
        'actions' => 'Actions',
        'add_new' => 'Add New',
        'delete_selected' => 'Delete selected',
    ],

    'labels' => [
        'record' => 'Record',
        'deleted' => 'Deleted',
        'created' => 'Created',
    ],

    'messages' => [
        'created' => 'Created successfully.',
        'updated' => 'Updated successfully.',
        'deleted' => 'Deleted successfully.',
        'restored' => 'Restored successfully.',
        'no_records' => 'No records found.',
        'confirm_delete' => 'Are you sure you want to delete this?',
        'confirm_force_delete' => 'Permanently delete this record?',
        'trash_empty' => 'Trash is empty.',
        'saved' => 'Saved.',
    ],

    'nav' => [
        'dashboard' => 'Dashboard',
        'profile' => 'Profile',
        'settings' => 'Settings',
        'logout' => 'Logout',
        'notifications' => 'Notifications',
        'trash' => 'Trash',
        'toggle_theme' => 'Toggle theme',
        'customize' => 'Customize',
        'fullscreen' => 'Fullscreen',
        'toggle_sidebar' => 'Toggle sidebar',
        'member' => 'Member',
    ],

    'auth' => [
        'sign_in' => 'Sign in',
        'sign_in_subtitle' => 'Sign in to your account',
        'email' => 'Email',
        'password' => 'Password',
        'remember_me' => 'Remember me',
    ],

    'footer' => [
        'rights' => 'All rights reserved.',
    ],

    'notifications' => [
        'title' => 'Notifications',
        'mark_all_read' => 'Mark all read',
        'see_all' => 'See all',
        'empty' => "You're all caught up.",
    ],

    'import' => [
        'help_question' => 'Not sure which columns to use?',
        'download_template' => 'Download a blank template',
        'help_rest' => 'fill in the rows and upload it. (Same shape as Export; invalid rows are skipped and reported.)',
    ],

    // Access module screens (users / roles / permissions / settings / profile / menu).
    'access' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'group' => 'Group',
        'guard' => 'Guard',
        'home' => 'Home',
        // users
        'users' => 'Users',
        'users_desc' => 'Manage user accounts and their access.',
        'create_user' => 'Create User',
        'edit_user' => 'Edit User',
        'password_keep' => 'Leave blank to keep current.',
        'no_roles' => 'No roles yet — create one first.',
        // roles
        'roles_title' => 'Roles',
        'roles_desc' => 'Define roles and the permissions they grant.',
        'create_role' => 'Create Role',
        'edit_role' => 'Edit Role',
        // permissions
        'permissions_title' => 'Permissions',
        'permissions_desc' => 'All permissions available to assign to roles.',
        'permissions_note' => 'Permissions are generated automatically by',
        // settings
        'settings' => 'Settings',
        'save_settings' => 'Save settings',
        'settings_empty' => 'No settings yet — seed some with',
        'current_file' => 'Current:',
        'replace_file_hint' => '— choose a file to replace it.',
        // profile
        'my_profile' => 'My Profile',
        'profile' => 'Profile',
        'change_avatar' => 'Change avatar',
        'change_password' => 'Change password',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_label' => 'Confirm',
        'update_password' => 'Update password',
        'crop_avatar' => 'Crop your avatar',
        // menu manager
        'menu' => 'Menu',
        'menu_desc' => 'Build the sidebar — drag a row to reorder, drag onto another to nest.',
        'add_item' => 'Add item',
        'add_menu_item' => 'Add menu item',
        'no_menu_items' => 'No menu items yet',
        'label' => 'Label',
        'icon' => 'Icon',
        'link' => 'Link',
        'route' => 'Route',
        'url' => 'URL',
        'named_route' => 'Named route',
        'custom_url' => 'Custom URL',
        'section_header' => 'None (section header)',
        'active_pattern' => 'Active highlight pattern',
        'optional' => '(optional)',
        'permission' => 'Permission',
        'visible_everyone' => '— visible to everyone —',
        'open_new_tab' => 'Open in a new tab',
        'active_sidebar' => 'Active (shown in the sidebar)',
        'hidden' => 'hidden',
        'header' => 'header',
        'icon_hint' => 'Browse names at icons.getbootstrap.com',
        'match_hint' => 'A request()->is() pattern that marks the item “active”.',
        'permission_hint' => 'Hidden unless the signed-in admin has this permission.',
    ],

    // JS dialogs (SweetAlert confirms + toastr toasts). :count is replaced client-side.
    'confirm' => [
        'title' => 'Are you sure?',
        'recover_one' => 'You will not be able to recover this record!',
        'recover_many' => 'You will not be able to recover them!',
        'yes_delete' => 'Yes, delete it!',
        'yes_delete_many' => 'Yes, delete them!',
        'no_keep' => 'No, keep it',
        'delete_count' => 'Delete :count record(s)?',
        'delete_all_errors' => 'Delete all error log entries?',
        'delete_menu_item' => 'Delete this menu item? Any children move up a level.',
    ],

    'toast' => [
        'deleted' => 'Deleted',
        'deleted_count' => 'Deleted :count record(s)',
        'order_updated' => 'Order updated',
        'error' => 'Something went wrong!',
    ],
];
