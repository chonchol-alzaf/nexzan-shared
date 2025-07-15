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
### Step 4: Publish the View File
```bash
php artisan vendor:publish --tag=views
```
---
Create token from: https://github.com/settings/tokens  
✅ Required scopes: `read:packages`, `repo`

### 📁 Package Structure
```
nexzan-shared/
├── src/
│   ├── Enums/
│   │   └── TeamStatusEnum.php
│   ├── Exceptions/
│   │   └── CustomException.php
│   ├── Http/
│   │   └── Requests/
│   │       └── BaseFormRequest.php
│   └── Models/
│   │    └── Team.php
│   └──Traits/
│        └── MicroServiceRequestTrait.php
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
