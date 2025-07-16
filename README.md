# 🧩 Nexzan Shared Package

Shared utility package for all Nexzan microservices like `atom`, `site`, and others.  
It includes reusable helpers, responses, views, and route definitions that can be shared across services.

---

## 📦 Installation

### Step 1: Add Private Repository

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/chonchol-alzaf/nexzan-shared"
  }
]
```

### Step 2: Require the Package

```bash
composer require chonchol-alzaf/nexzan-shared:^1.0
```

## GitHub Token 

```bash
composer config --global github-oauth.github.com YOUR_PERSONAL_ACCESS_TOKEN
```
🔐 Generate token: https://github.com/settings/tokens

✅ Required scopes: `read:packages`, `repo`

---

### Step 4: Publish Config & View Files
```bash
php artisan vendor:publish --tag=nexzan-shared-views
php artisan vendor:publish --tag=nexzan-shared-config
```

---

## ⚙️ Configuration

### Publish Configuration File

```bash
php artisan vendor:publish --tag=nexzan-shared-config
```

Creates:

```
config/nexzan-shared.php
```

---

## 🖼️ Views

### Publish Views

```bash
php artisan vendor:publish --tag=nexzan-shared-views
```

Publishes to:

```
resources/views/vendor/nexzan-shared/
```

### Load View

```php
return view('nexzan-shared::emails.log-alert');
```

---

## 🛣️ Routes

Routes are automatically loaded from:

```
routes/v1/micro-service/api.php
```

###  Endpoints

```
POST /v1/internal/team-status
```
---

## 🧰 Helper Functions

These global functions are available automatically.

### ✅ `ResponseSuccess($data = null, $message = 'Success', $status = 200)`

Send a success JSON response.

```php
return ResponseSuccess($users, 'User list loaded');
```

---

### ❌ `ResponseError($message = null, $status = 500, $throwable = null)`

Send a failure response with optional exception.

```php
return ResponseError('Something went wrong');
```

- Logs error in `default` and `mail` channels
- Supports:
  - `CustomException`
  - `CloudPanelException` (only if the class exists in the app)

---

### 📊 `PaginateMetaData($paginator)`

Returns useful metadata from paginator instance.

```php
PaginateMetaData(User::paginate());
```
---

### 📁 Package Structure

<img width="420" height="641" alt="image" src="https://github.com/user-attachments/assets/2fea9664-7c8a-46f1-bf50-54f076b98994" />

---
🔄 Versioning & Git Tagging
```bash
git tag v1.0.0
git push origin v1.0.0
```

## ✅ Use Cases

- Shared API response format (`ResponseSuccess`, `ResponseError`)
- Reusable blade views
- Shared internal routes
- Central configuration management

---

## 🔮 Future Ideas

- [ ] Add traits, macros, or middleware
- [ ] Add unit tests for helper functions
- [ ] Add localization for messages

---

Maintained by [Alzaf](https://alzaf.com) 🛠️
