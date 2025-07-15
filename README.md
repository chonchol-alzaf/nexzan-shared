# 🔐 Nexzan Shared (Private Package)
Shared same feature of codes for all Nexzan services 

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

### Step 2: Require the Package via Composer

```bash
composer require chonchol-alzaf/nexzan-shared:^1.0.0
```

### Step 3: GitHub Token 

```bash
composer config --global github-oauth.github.com YOUR_PERSONAL_ACCESS_TOKEN
```
### Step 4: (Optional) Publish the View File
```bash
php artisan vendor:publish --tag=views
```

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
3️⃣ Add a new key before the `channels` array (usually at the top of the file):

```php
'log_notification_email' => env('LOG_NOTIFICATION_EMAIL', 'dev@nexzan.com'),
```


---
Create token from: https://github.com/settings/tokens  
✅ Required scopes: `read:packages`, `repo`

### 📁 Package Structure
```
nexzan-shared/
├── src/
│   ├── Broadcasting/
│   │     └── LogEmailHandler.php
│   ├── Enums/
│   │   └── TeamStatusEnum.php
│   ├── Exceptions/
│   │   └── CustomException.php
│   ├── Http/
│   │   └── Requests/
│   │       └── BaseFormRequest.php
│   ├── Mail/
│   │   └── LogAlertMail.php
│   └── Models/
│   │    └── Team.php
│   │
│   └── Providers/
│   │    └── NexzanSharedServiceProvider.php
│   └──Traits/
│        └── MicroServiceRequestTrait.php
├── resources/
│      └── views/
│           └──emails/
│              └── log-alert.blade.php
├── composer.json
└── README.md
```


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
