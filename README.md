# 🔐 Nexzan Shared (Private Package)
Reusable components shared across all Nexzan services.

---
📦 Installation (Private GitHub Package)
### Step 1: Add Private Repository to `composer.json`

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/chonchol-alzaf/nexzan-shared"
  }
]
```
---

### Step 2: Require the Package via Composer

```bash
composer require chonchol-alzaf/nexzan-shared:^1.0.0
```
---

### Step 3: GitHub Token 

```bash
composer config --global github-oauth.github.com YOUR_PERSONAL_ACCESS_TOKEN
```
🔐 Generate token: https://github.com/settings/tokens

✅ Required scopes: `read:packages`, `repo`

### Step 4: Publish Config & View Files
```bash
php artisan vendor:publish --tag=nexzan-shared-views
php artisan vendor:publish --tag=nexzan-shared-config
```

---

### 📨 Step 5: (Optional) Use Mail Logging Channel
If you want to send log messages via email (for example: *critical*, *error*, or *warning*), add a custom mail channel in your Laravel logging config.

1️⃣ Open `config/logging.php`

2️⃣ Add a new channel to the `channels` array:
```php
 'mail' => [
    'driver' => 'monolog',
    'level' => 'debug',
    'handler' => Nexzan\Shared\Broadcasting\LogEmailHandler::class,
],
```

---


### 📁 Package Highlights
- 📌 Shared API route: `v1/internal/team-status`
- 🧩 Common Traits, Enums, Models, Exceptions, and Requests
- 🧠 Designed for modular Laravel-based microservices..

---

### 📁 Package Structure

<img width="420" height="641" alt="image" src="https://github.com/user-attachments/assets/2fea9664-7c8a-46f1-bf50-54f076b98994" />

---
🔄 Versioning & Git Tagging
```bash
git tag v1.0.0
git push origin v1.0.0
```

Then update in other projects:

```bash
composer update chonchol-alzaf/nexzan-shared
```

📬 Contributions
You may extend the shared package by:

- Adding new Enums  
- Adding base Traits / Models  
- Creating shared Exceptions or Requests
- Create relevant any class

All changes should go through pull requests (PRs).
### 🧪 Tested With
- Laravel 10.x / 11.x  
- PHP 8.1 / 8.2+  
- Composer 2.x
