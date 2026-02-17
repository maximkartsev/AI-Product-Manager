# AI Video Effects SaaS Platform -- API Documentation

**Base URL:** `/api/`

**Authentication:** Laravel Sanctum (Bearer Token)

**Architecture:** Multi-tenant, tenant-scoped data isolation

---

## Table of Contents

- [Standard Response Format](#standard-response-format)
- [Authentication](#authentication)
- [Pagination, Search, and Filtering](#pagination-search-and-filtering)
- [Common Error Codes](#common-error-codes)
- [Public Endpoints](#public-endpoints)
  - [POST /api/register](#post-apiregister)
  - [POST /api/login](#post-apilogin)
  - [GET /api/effects](#get-apieffects)
  - [GET /api/effects/{slugOrId}](#get-apieffectsslugorid)
  - [GET /api/categories](#get-apicategories)
  - [GET /api/categories/{slugOrId}](#get-apicategoriesslugorid)
  - [GET /api/gallery](#get-apigallery)
  - [GET /api/gallery/{id}](#get-apigalleryid)
- [Authenticated Endpoints](#authenticated-endpoints)
  - [GET /api/me](#get-apime)
  - [POST /api/me](#post-apime)
  - [GET /api/wallet](#get-apiwallet)
  - [POST /api/ai-jobs](#post-apiai-jobs)
  - [POST /api/videos/uploads](#post-apivideouploads)
  - [GET /api/videos](#get-apivideos)
  - [POST /api/videos](#post-apivideos)
  - [GET /api/videos/{id}](#get-apivideosid)
  - [PATCH /api/videos/{id}](#patch-apivideosid)
  - [DELETE /api/videos/{id}](#delete-apivideosid)
  - [POST /api/videos/{id}/publish](#post-apivideosidpublish)
  - [POST /api/videos/{id}/unpublish](#post-apivideosidunpublish)
- [Admin Endpoints](#admin-endpoints)
  - [Effects Management](#admin-effects-management)
  - [Categories Management](#admin-categories-management)
  - [Users Management](#admin-users-management)
  - [Analytics](#admin-analytics)

---

## Standard Response Format

### Success Response

```json
{
  "success": true,
  "data": { ... },
  "message": "Description of what happened"
}
```

### Error Response

```json
{
  "success": false,
  "message": "Human-readable error message",
  "data": { "field": ["error detail"] }
}
```

The `data` field in error responses is only present for validation errors (HTTP 422). For all other error codes, only `success` and `message` are returned.

### Validation Error Response (422)

```json
{
  "success": false,
  "message": "Validation Error",
  "data": {
    "name": ["The name field is required."],
    "slug": ["The slug has already been taken."]
  }
}
```

---

## Authentication

This API uses **Laravel Sanctum** bearer tokens. After registering or logging in, include the returned token in the `Authorization` header of all authenticated requests:

```
Authorization: Bearer {token}
```

All authenticated endpoints are **tenant-scoped**: users can only access data belonging to their own tenant.

---

## Pagination, Search, and Filtering

All list endpoints support the following query parameters:

| Parameter | Type    | Default | Description                                                                 |
|-----------|---------|---------|-----------------------------------------------------------------------------|
| `page`    | integer | `1`     | Page number                                                                 |
| `perPage` | integer | `50`    | Items per page                                                              |
| `search`  | string  | —       | Full-text LIKE search across relevant fields                                |
| `order`   | string  | —       | Sort order in `field:direction` format (e.g. `created_at:desc`, `id:asc`)   |

### Filtering

Filters are passed as query parameters using the format:

```
field:operator=value
```

**Supported operators:**

| Operator   | Example                                        | Description               |
|------------|------------------------------------------------|---------------------------|
| `=`        | `status:=completed`                            | Exact match               |
| `between`  | `created_at:between=2026-01-01,2026-12-31`     | Range (inclusive)          |

### Paginated Response Structure

```json
{
  "success": true,
  "data": {
    "items": [ ... ],
    "totalItems": 128,
    "totalPages": 3,
    "page": 1,
    "perPage": 50,
    "order": "created_at:desc",
    "search": "",
    "filters": {}
  },
  "message": "Resources retrieved successfully"
}
```

---

## Common Error Codes

| Code | Meaning                  | Description                                                        |
|------|--------------------------|--------------------------------------------------------------------|
| 401  | Unauthenticated          | Missing or invalid bearer token                                    |
| 403  | Forbidden                | Not authorized for this action, or resource ownership mismatch     |
| 404  | Not Found                | The requested resource does not exist                              |
| 422  | Unprocessable Entity     | Validation error or business rule violation                        |
| 500  | Internal Server Error    | Temporary server issue -- retry the request                        |

---

## Public Endpoints

These endpoints do not require authentication.

---

### POST /api/register

Register a new user account and create their tenant.

**Auth:** None

**Request Body:**

| Field        | Type   | Rules                                    | Description              |
|--------------|--------|------------------------------------------|--------------------------|
| `name`       | string | required, max:255                        | Display name             |
| `email`      | string | required, email, max:255, unique         | Account email            |
| `password`   | string | required, min:8                          | Password                 |
| `c_password` | string | required, same:password                  | Password confirmation    |
| `first_name` | string | nullable, max:255                        | First name (optional)    |
| `last_name`  | string | nullable, max:255                        | Last name (optional)     |

**Example Request:**

```json
{
  "name": "johndoe",
  "email": "john@example.com",
  "password": "securepass123",
  "c_password": "securepass123",
  "first_name": "John",
  "last_name": "Doe"
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456ghi789...",
    "name": "johndoe",
    "tenant": {
      "id": 1,
      "domain": "johndoe.example.com",
      "db_pool": "tenant_pool_01"
    }
  },
  "message": "User register successfully"
}
```

**Error Responses:**

| Code | Condition                                           |
|------|-----------------------------------------------------|
| 422  | Validation errors (missing fields, email taken, password mismatch, etc.) |
| 500  | Server error                                        |

---

### POST /api/login

Authenticate an existing user and receive a bearer token.

**Auth:** None

**Request Body:**

| Field      | Type   | Rules          | Description    |
|------------|--------|----------------|----------------|
| `email`    | string | required, email | Account email  |
| `password` | string | required        | Password       |

**Example Request:**

```json
{
  "email": "john@example.com",
  "password": "securepass123"
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "token": "2|xyz789abc456def123...",
    "name": "johndoe",
    "tenant": {
      "id": 1,
      "domain": "johndoe.example.com",
      "db_pool": "tenant_pool_01"
    }
  },
  "message": "User login successfully"
}
```

**Error Responses:**

| Code | Condition                          |
|------|------------------------------------|
| 401  | Incorrect email or password        |
| 422  | Validation errors (missing fields) |
| 500  | Server error                       |

---

### GET /api/effects

List all active AI effects from the public catalog.

**Auth:** None

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "Cartoon Style",
        "slug": "cartoon-style",
        "description": "Transform your video into a cartoon animation.",
        "category_id": 3,
        "tags": ["cartoon", "animation", "fun"],
        "type": "video_transform",
        "thumbnail_url": "https://cdn.example.com/effects/cartoon-thumb.jpg",
        "preview_video_url": "https://cdn.example.com/effects/cartoon-preview.mp4",
        "credits_cost": 10,
        "is_active": true,
        "is_premium": false,
        "is_new": true
      }
    ],
    "totalItems": 42,
    "totalPages": 1,
    "page": 1,
    "perPage": 50,
    "order": "id:asc",
    "search": "",
    "filters": {}
  },
  "message": "Effects retrieved successfully"
}
```

**Error Responses:**

| Code | Condition    |
|------|--------------|
| 500  | Server error |

---

### GET /api/effects/{slugOrId}

Get details for a single effect by its slug or numeric ID.

**Auth:** None

**URL Parameters:**

| Parameter    | Type          | Description                     |
|--------------|---------------|---------------------------------|
| `slugOrId`   | string or int | Effect slug (e.g. `cartoon-style`) or numeric ID |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Cartoon Style",
    "slug": "cartoon-style",
    "description": "Transform your video into a cartoon animation.",
    "category_id": 3,
    "tags": ["cartoon", "animation", "fun"],
    "type": "video_transform",
    "thumbnail_url": "https://cdn.example.com/effects/cartoon-thumb.jpg",
    "preview_video_url": "https://cdn.example.com/effects/cartoon-preview.mp4",
    "credits_cost": 10,
    "is_active": true,
    "is_premium": false,
    "is_new": true,
    "created_at": "2026-01-15T10:30:00.000000Z",
    "updated_at": "2026-02-01T08:00:00.000000Z"
  },
  "message": "Effect retrieved successfully"
}
```

**Error Responses:**

| Code | Condition               |
|------|-------------------------|
| 404  | Effect not found        |
| 500  | Server error            |

---

### GET /api/categories

List all effect categories.

**Auth:** None

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "Artistic",
        "slug": "artistic",
        "description": "Artistic and creative video transformations.",
        "sort_order": 1
      },
      {
        "id": 2,
        "name": "Fun & Social",
        "slug": "fun-social",
        "description": "Fun effects perfect for social media.",
        "sort_order": 2
      }
    ],
    "totalItems": 8,
    "totalPages": 1,
    "page": 1,
    "perPage": 50
  },
  "message": "Categories retrieved successfully"
}
```

**Error Responses:**

| Code | Condition    |
|------|--------------|
| 500  | Server error |

---

### GET /api/categories/{slugOrId}

Get details for a single category by slug or numeric ID.

**Auth:** None

**URL Parameters:**

| Parameter    | Type          | Description                        |
|--------------|---------------|------------------------------------|
| `slugOrId`   | string or int | Category slug or numeric ID        |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Artistic",
    "slug": "artistic",
    "description": "Artistic and creative video transformations.",
    "sort_order": 1,
    "created_at": "2026-01-10T12:00:00.000000Z",
    "updated_at": "2026-01-10T12:00:00.000000Z"
  },
  "message": "Category retrieved successfully"
}
```

**Error Responses:**

| Code | Condition               |
|------|-------------------------|
| 404  | Category not found      |
| 500  | Server error            |

---

### GET /api/gallery

Browse the public gallery of user-published videos.

**Auth:** None

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 101,
        "title": "My Cartoon Selfie",
        "thumbnail_url": "https://cdn.example.com/videos/101/thumb.jpg",
        "processed_file_url": "https://cdn.example.com/videos/101/output.mp4",
        "effect": {
          "id": 1,
          "name": "Cartoon Style",
          "slug": "cartoon-style"
        },
        "published_at": "2026-02-10T14:22:00.000000Z"
      }
    ],
    "totalItems": 256,
    "totalPages": 6,
    "page": 1,
    "perPage": 50,
    "order": "published_at:desc",
    "search": "",
    "filters": {}
  },
  "message": "Gallery videos retrieved successfully"
}
```

**Error Responses:**

| Code | Condition    |
|------|--------------|
| 500  | Server error |

---

### GET /api/gallery/{id}

Get details for a single gallery video.

**Auth:** None

**URL Parameters:**

| Parameter | Type    | Description       |
|-----------|---------|-------------------|
| `id`      | integer | Gallery video ID  |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 101,
    "title": "My Cartoon Selfie",
    "thumbnail_url": "https://cdn.example.com/videos/101/thumb.jpg",
    "processed_file_url": "https://cdn.example.com/videos/101/output.mp4",
    "effect": {
      "id": 1,
      "name": "Cartoon Style",
      "slug": "cartoon-style"
    },
    "published_at": "2026-02-10T14:22:00.000000Z"
  },
  "message": "Gallery video retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 404  | Gallery video not found        |
| 500  | Server error                   |

---

## Authenticated Endpoints

All endpoints in this section require a valid Sanctum bearer token in the `Authorization` header. All data is **tenant-scoped** to the authenticated user's tenant.

```
Authorization: Bearer {token}
```

---

### GET /api/me

Get the currently authenticated user's profile.

**Auth:** Bearer token (required)

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "johndoe",
    "email": "john@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "created_at": "2026-01-15T10:30:00.000000Z",
    "updated_at": "2026-02-01T08:00:00.000000Z"
  },
  "message": "User retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                          |
|------|------------------------------------|
| 401  | Unauthenticated (missing/invalid token) |
| 500  | Server error                       |

---

### POST /api/me

Update the currently authenticated user's profile. Only whitelisted fields are accepted.

**Auth:** Bearer token (required)

**Request Body:**

All fields are optional. Only include the fields you want to update.

| Field        | Type   | Rules                                       | Description          |
|--------------|--------|---------------------------------------------|----------------------|
| `name`       | string | sometimes, max:255                          | Display name         |
| `first_name` | string | sometimes, max:255                          | First name           |
| `last_name`  | string | sometimes, max:255                          | Last name            |
| `email`      | string | sometimes, email, max:255, unique           | Account email        |
| `password`   | string | sometimes, min:8                            | New password         |

**Example Request:**

```json
{
  "first_name": "Jonathan",
  "last_name": "Doe"
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "johndoe",
    "email": "john@example.com",
    "first_name": "Jonathan",
    "last_name": "Doe",
    "created_at": "2026-01-15T10:30:00.000000Z",
    "updated_at": "2026-02-14T09:15:00.000000Z"
  },
  "message": "User updated successfully"
}
```

**Error Responses:**

| Code | Condition                                  |
|------|--------------------------------------------|
| 401  | Unauthenticated                            |
| 422  | Validation errors (e.g. email already taken, password too short) |
| 500  | Server error                               |

---

### GET /api/wallet

Get the authenticated user's token/credit wallet balance.

**Auth:** Bearer token (required)

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "balance": 150,
    "currency": "credits"
  },
  "message": "Wallet retrieved successfully"
}
```

**Error Responses:**

| Code | Condition       |
|------|-----------------|
| 401  | Unauthenticated |
| 500  | Server error    |

---

### POST /api/ai-jobs

Submit an AI video processing job. The system will dispatch the job to the appropriate AI provider.

**Auth:** Bearer token (required)

**Business Rules:**
- Maximum **5 concurrent processing jobs** per user. Exceeding this limit returns a 422 error.
- The user must have **sufficient token balance** to cover the effect's `credits_cost`. Insufficient balance returns a 422 error.

**Request Body:**

| Field             | Type    | Rules                           | Description                                      |
|-------------------|---------|---------------------------------|--------------------------------------------------|
| `effect_id`       | integer | required, exists:effects        | ID of the effect to apply                        |
| `idempotency_key` | string  | required, max:255               | Client-generated unique key to prevent duplicates |
| `provider`        | string  | nullable                        | AI provider override (uses default if omitted)   |
| `video_id`        | integer | nullable                        | Existing video record ID                         |
| `input_file_id`   | integer | nullable                        | Uploaded file ID                                 |
| `input_payload`   | array   | nullable                        | Additional input data for the effect             |
| `priority`        | integer | nullable                        | Job priority (higher = more urgent)              |

**Example Request:**

```json
{
  "effect_id": 1,
  "idempotency_key": "usr1-cartoon-abc123",
  "video_id": 42,
  "input_payload": {
    "intensity": 0.8
  }
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 77,
    "dispatch_id": "job_abc123def456",
    "effect_id": 1,
    "video_id": 42,
    "status": "queued",
    "created_at": "2026-02-14T10:00:00.000000Z"
  },
  "message": "AI job submitted successfully"
}
```

**Error Responses:**

| Code | Condition                                                  |
|------|------------------------------------------------------------|
| 401  | Unauthenticated                                            |
| 403  | Ownership mismatch (video/file belongs to another user)    |
| 404  | Effect, video, or file not found                           |
| 422  | Validation errors, insufficient token balance, or maximum concurrent jobs reached |
| 500  | Server error                                               |

---

### POST /api/videos/uploads

Initialize a video upload and receive a presigned S3 URL for direct client-side upload.

**Auth:** Bearer token (required)

**Request Body:**

| Field               | Type    | Rules                          | Description                          |
|---------------------|---------|--------------------------------|--------------------------------------|
| `effect_id`         | integer | required, exists:effects       | Target effect for this upload        |
| `mime_type`         | string  | required                       | MIME type (e.g. `video/mp4`)         |
| `size`              | integer | required, min:1                | File size in bytes                   |
| `original_filename` | string  | required, max:512              | Original file name                   |
| `file_hash`         | string  | nullable                       | File hash for deduplication          |

**Example Request:**

```json
{
  "effect_id": 1,
  "mime_type": "video/mp4",
  "size": 15728640,
  "original_filename": "my-video.mp4"
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "file": {
      "id": 88,
      "original_filename": "my-video.mp4",
      "mime_type": "video/mp4",
      "size": 15728640,
      "status": "pending_upload"
    },
    "upload_url": "https://s3.amazonaws.com/bucket/uploads/88?X-Amz-...",
    "upload_headers": {
      "Content-Type": "video/mp4",
      "x-amz-meta-file-id": "88"
    },
    "expires_in": 3600
  },
  "message": "Upload initialized successfully"
}
```

**Error Responses:**

| Code | Condition                                                  |
|------|------------------------------------------------------------|
| 401  | Unauthenticated                                            |
| 422  | Validation errors, insufficient token balance, unsupported MIME type, or file too large |
| 500  | Server error                                               |

---

### GET /api/videos

List the authenticated user's videos.

**Auth:** Bearer token (required)

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 42,
        "title": "My Cartoon Video",
        "status": "completed",
        "original_file_url": "https://cdn.example.com/videos/42/original.mp4",
        "processed_file_url": "https://cdn.example.com/videos/42/output.mp4",
        "error": null,
        "effect": {
          "id": 1,
          "name": "Cartoon Style",
          "slug": "cartoon-style"
        },
        "created_at": "2026-02-12T09:00:00.000000Z",
        "updated_at": "2026-02-12T09:05:00.000000Z"
      }
    ],
    "totalItems": 12,
    "totalPages": 1,
    "page": 1,
    "perPage": 50,
    "order": "created_at:desc",
    "search": "",
    "filters": {}
  },
  "message": "Videos retrieved successfully"
}
```

**Error Responses:**

| Code | Condition       |
|------|-----------------|
| 401  | Unauthenticated |
| 500  | Server error    |

---

### POST /api/videos

Create a new video record linked to an uploaded file and an effect.

**Auth:** Bearer token (required)

**Request Body:**

| Field              | Type    | Rules                    | Description                     |
|--------------------|---------|--------------------------|---------------------------------|
| `effect_id`        | integer | required, exists:effects | Effect to apply                 |
| `original_file_id` | integer | required                 | ID of the uploaded file         |
| `title`            | string  | nullable, max:255        | Video title                     |
| `input_payload`    | array   | nullable                 | Additional parameters for the effect |

**Example Request:**

```json
{
  "effect_id": 1,
  "original_file_id": 88,
  "title": "My Cartoon Video"
}
```

**Success Response (201):**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "title": "My Cartoon Video",
    "status": "pending",
    "effect_id": 1,
    "original_file_id": 88,
    "created_at": "2026-02-14T10:30:00.000000Z",
    "updated_at": "2026-02-14T10:30:00.000000Z"
  },
  "message": "Video created successfully"
}
```

**Error Responses:**

| Code | Condition                                     |
|------|-----------------------------------------------|
| 401  | Unauthenticated                               |
| 403  | File ownership mismatch (file belongs to another user) |
| 404  | File or effect not found                      |
| 422  | Validation errors                             |
| 500  | Server error                                  |

---

### GET /api/videos/{id}

Get details for a specific video, including presigned file URLs.

**Auth:** Bearer token (required)

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Video ID    |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "title": "My Cartoon Video",
    "status": "completed",
    "original_file_url": "https://cdn.example.com/videos/42/original.mp4?X-Amz-...",
    "processed_file_url": "https://cdn.example.com/videos/42/output.mp4?X-Amz-...",
    "error": null,
    "effect": {
      "id": 1,
      "name": "Cartoon Style",
      "slug": "cartoon-style"
    },
    "created_at": "2026-02-12T09:00:00.000000Z",
    "updated_at": "2026-02-12T09:05:00.000000Z"
  },
  "message": "Video retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                         |
|------|-----------------------------------|
| 401  | Unauthenticated                   |
| 403  | Not the owner of this video       |
| 404  | Video not found                   |
| 500  | Server error                      |

---

### PATCH /api/videos/{id}

Update details for an existing video.

**Auth:** Bearer token (required)

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Video ID    |

**Request Body:**

Fields that can be updated (all optional):

| Field   | Type   | Rules              | Description     |
|---------|--------|--------------------|-----------------|
| `title` | string | sometimes, max:255 | Video title     |

**Example Request:**

```json
{
  "title": "Updated Title"
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "title": "Updated Title",
    "status": "completed",
    "created_at": "2026-02-12T09:00:00.000000Z",
    "updated_at": "2026-02-14T11:00:00.000000Z"
  },
  "message": "Video updated successfully"
}
```

**Error Responses:**

| Code | Condition                   |
|------|-----------------------------|
| 401  | Unauthenticated             |
| 403  | Not the owner of this video |
| 404  | Video not found             |
| 422  | Validation errors           |
| 500  | Server error                |

---

### DELETE /api/videos/{id}

Soft-delete a video. If the video is published to the gallery, it will be automatically unpublished.

**Auth:** Bearer token (required)

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Video ID    |

**Success Response (204):** No content.

**Error Responses:**

| Code | Condition                   |
|------|-----------------------------|
| 401  | Unauthenticated             |
| 403  | Not the owner of this video |
| 404  | Video not found             |
| 500  | Server error                |

---

### POST /api/videos/{id}/publish

Publish a video to the public gallery.

**Auth:** Bearer token (required)

**Business Rules:**
- The video must have a `completed` status before it can be published.

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Video ID    |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "title": "My Cartoon Video",
    "status": "completed",
    "is_published": true,
    "published_at": "2026-02-14T12:00:00.000000Z"
  },
  "message": "Video published successfully"
}
```

**Error Responses:**

| Code | Condition                                           |
|------|-----------------------------------------------------|
| 401  | Unauthenticated                                     |
| 403  | Not the owner of this video                         |
| 422  | Video is not in `completed` status                  |
| 500  | Server error                                        |

---

### POST /api/videos/{id}/unpublish

Remove a video from the public gallery.

**Auth:** Bearer token (required)

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Video ID    |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "title": "My Cartoon Video",
    "status": "completed",
    "is_published": false,
    "published_at": null
  },
  "message": "Video unpublished successfully"
}
```

**Error Responses:**

| Code | Condition                   |
|------|-----------------------------|
| 401  | Unauthenticated             |
| 403  | Not the owner of this video |
| 500  | Server error                |

---

## Admin Endpoints

All admin endpoints require a valid Sanctum bearer token **and** the authenticated user must have the **admin** role. Unauthorized access returns a 403 error.

```
Authorization: Bearer {admin-token}
```

---

### Admin Effects Management

#### GET /api/admin/effects

List all effects including soft-deleted ones (admin view).

**Auth:** Bearer token + Admin role

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "Cartoon Style",
        "slug": "cartoon-style",
        "description": "Transform your video into a cartoon animation.",
        "category_id": 3,
        "type": "video_transform",
        "credits_cost": 10,
        "is_active": true,
        "is_premium": false,
        "is_new": true,
        "popularity_score": 85,
        "deleted_at": null,
        "created_at": "2026-01-15T10:30:00.000000Z",
        "updated_at": "2026-02-01T08:00:00.000000Z"
      }
    ],
    "totalItems": 50,
    "totalPages": 1,
    "page": 1,
    "perPage": 50
  },
  "message": "Effects retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 500  | Server error                   |

---

#### POST /api/admin/effects

Create a new effect.

**Auth:** Bearer token + Admin role

**Request Body:**

| Field              | Type    | Rules                              | Description                       |
|--------------------|---------|------------------------------------|-----------------------------------|
| `name`             | string  | required, max:255                  | Effect display name               |
| `slug`             | string  | required, max:255, unique          | URL-friendly identifier           |
| `type`             | string  | required                           | Effect type                       |
| `credits_cost`     | numeric | required                           | Token cost per use                |
| `is_active`        | boolean | required                           | Whether the effect is active      |
| `is_premium`       | boolean | required                           | Whether the effect is premium     |
| `is_new`           | boolean | required                           | Whether to show "new" badge       |
| `popularity_score` | numeric | required                           | Popularity ranking score          |
| `description`      | string  | nullable                           | Effect description                |
| `category_id`      | integer | nullable                           | Category ID                       |
| `tags`             | array   | nullable                           | Array of tag strings              |
| `thumbnail_url`    | string  | nullable                           | Thumbnail image URL               |
| `preview_video_url`| string  | nullable                           | Preview video URL                 |

**Example Request:**

```json
{
  "name": "Oil Painting",
  "slug": "oil-painting",
  "type": "video_transform",
  "credits_cost": 15,
  "is_active": true,
  "is_premium": true,
  "is_new": true,
  "popularity_score": 50,
  "description": "Transform your video into an oil painting masterpiece.",
  "category_id": 1,
  "tags": ["art", "painting", "classic"]
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 51,
    "name": "Oil Painting",
    "slug": "oil-painting",
    "type": "video_transform",
    "credits_cost": 15,
    "is_active": true,
    "is_premium": true,
    "is_new": true,
    "popularity_score": 50,
    "description": "Transform your video into an oil painting masterpiece.",
    "category_id": 1,
    "tags": ["art", "painting", "classic"],
    "created_at": "2026-02-14T13:00:00.000000Z",
    "updated_at": "2026-02-14T13:00:00.000000Z"
  },
  "message": "Effect created successfully"
}
```

**Error Responses:**

| Code | Condition                                    |
|------|----------------------------------------------|
| 401  | Unauthenticated                              |
| 403  | User does not have admin role                |
| 422  | Validation errors (missing fields, duplicate slug, etc.) |
| 500  | Server error                                 |

---

#### PATCH /api/admin/effects/{id}

Update an existing effect.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Effect ID   |

**Request Body:** Same fields as creation, all optional. Only include fields to update.

**Success Response (200):**

```json
{
  "success": true,
  "data": { ... },
  "message": "Effect updated successfully"
}
```

**Error Responses:**

| Code | Condition                                    |
|------|----------------------------------------------|
| 401  | Unauthenticated                              |
| 403  | User does not have admin role                |
| 404  | Effect not found                             |
| 422  | Validation errors (e.g. duplicate slug)      |
| 500  | Server error                                 |

---

#### DELETE /api/admin/effects/{id}

Soft-delete an effect. The effect will no longer appear in the public catalog but is retained in the database.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | Effect ID   |

**Success Response (200):**

```json
{
  "success": true,
  "data": null,
  "message": "Effect deleted successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 404  | Effect not found               |
| 500  | Server error                   |

---

### Admin Categories Management

#### GET /api/admin/categories

List all categories (admin view).

**Auth:** Bearer token + Admin role

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):** Paginated list of categories.

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 500  | Server error                   |

---

#### POST /api/admin/categories

Create a new category.

**Auth:** Bearer token + Admin role

**Request Body:**

| Field         | Type    | Rules                        | Description                  |
|---------------|---------|------------------------------|------------------------------|
| `name`        | string  | required, max:255            | Category display name        |
| `slug`        | string  | required, max:255, unique    | URL-friendly identifier      |
| `sort_order`  | numeric | required                     | Display sort order           |
| `description` | string  | nullable                     | Category description         |

**Example Request:**

```json
{
  "name": "Retro",
  "slug": "retro",
  "sort_order": 5,
  "description": "Retro and vintage video effects."
}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 9,
    "name": "Retro",
    "slug": "retro",
    "sort_order": 5,
    "description": "Retro and vintage video effects.",
    "created_at": "2026-02-14T14:00:00.000000Z",
    "updated_at": "2026-02-14T14:00:00.000000Z"
  },
  "message": "Category created successfully"
}
```

**Error Responses:**

| Code | Condition                                    |
|------|----------------------------------------------|
| 401  | Unauthenticated                              |
| 403  | User does not have admin role                |
| 422  | Validation errors (missing fields, duplicate slug) |
| 500  | Server error                                 |

---

#### PATCH /api/admin/categories/{id}

Update an existing category.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description  |
|-----------|---------|--------------|
| `id`      | integer | Category ID  |

**Request Body:** Same fields as creation, all optional.

**Success Response (200):**

```json
{
  "success": true,
  "data": { ... },
  "message": "Category updated successfully"
}
```

**Error Responses:**

| Code | Condition                                    |
|------|----------------------------------------------|
| 401  | Unauthenticated                              |
| 403  | User does not have admin role                |
| 404  | Category not found                           |
| 422  | Validation errors (e.g. duplicate slug)      |
| 500  | Server error                                 |

---

#### DELETE /api/admin/categories/{id}

Delete a category.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description  |
|-----------|---------|--------------|
| `id`      | integer | Category ID  |

**Success Response (200):**

```json
{
  "success": true,
  "data": null,
  "message": "Category deleted successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 404  | Category not found             |
| 500  | Server error                   |

---

### Admin Users Management

#### GET /api/admin/users

List all users across tenants.

**Auth:** Bearer token + Admin role

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "johndoe",
        "email": "john@example.com",
        "first_name": "John",
        "last_name": "Doe",
        "created_at": "2026-01-15T10:30:00.000000Z"
      }
    ],
    "totalItems": 340,
    "totalPages": 7,
    "page": 1,
    "perPage": 50
  },
  "message": "Users retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 500  | Server error                   |

---

#### GET /api/admin/users/{id}

Get detailed information for a specific user, including tenant details.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | User ID     |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "johndoe",
    "email": "john@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "tenant": {
      "id": 1,
      "domain": "johndoe.example.com",
      "db_pool": "tenant_pool_01"
    },
    "created_at": "2026-01-15T10:30:00.000000Z",
    "updated_at": "2026-02-01T08:00:00.000000Z"
  },
  "message": "User retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 404  | User not found                 |
| 500  | Server error                   |

---

#### GET /api/admin/users/{id}/purchases

Get a user's purchase history.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | User ID     |

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 10,
        "user_id": 1,
        "amount": 9.99,
        "tokens_purchased": 100,
        "status": "completed",
        "payment_provider": "stripe",
        "created_at": "2026-02-01T12:00:00.000000Z"
      }
    ],
    "totalItems": 3,
    "totalPages": 1,
    "page": 1,
    "perPage": 50
  },
  "message": "Purchases retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 404  | User not found                 |
| 500  | Server error                   |

---

#### GET /api/admin/users/{id}/tokens

Get a user's token transaction history.

**Auth:** Bearer token + Admin role

**URL Parameters:**

| Parameter | Type    | Description |
|-----------|---------|-------------|
| `id`      | integer | User ID     |

**Query Parameters:** Standard [pagination, search, and filtering](#pagination-search-and-filtering) parameters.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 55,
        "user_id": 1,
        "type": "debit",
        "amount": 10,
        "balance_after": 140,
        "description": "Applied effect: Cartoon Style",
        "reference_type": "ai_job",
        "reference_id": 77,
        "created_at": "2026-02-14T10:00:00.000000Z"
      },
      {
        "id": 50,
        "user_id": 1,
        "type": "credit",
        "amount": 100,
        "balance_after": 150,
        "description": "Token purchase",
        "reference_type": "purchase",
        "reference_id": 10,
        "created_at": "2026-02-01T12:00:00.000000Z"
      }
    ],
    "totalItems": 15,
    "totalPages": 1,
    "page": 1,
    "perPage": 50
  },
  "message": "Token transactions retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 404  | User not found                 |
| 500  | Server error                   |

---

### Admin Analytics

#### GET /api/admin/analytics/token-spending

Get aggregated token spending analytics across the platform.

**Auth:** Bearer token + Admin role

**Query Parameters:** Standard filtering parameters (e.g. date ranges via `created_at:between`).

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "total_tokens_spent": 12450,
    "total_transactions": 834,
    "period": {
      "from": "2026-01-01",
      "to": "2026-02-14"
    },
    "by_effect": [
      {
        "effect_id": 1,
        "effect_name": "Cartoon Style",
        "tokens_spent": 3200,
        "usage_count": 320
      }
    ]
  },
  "message": "Token spending analytics retrieved successfully"
}
```

**Error Responses:**

| Code | Condition                      |
|------|--------------------------------|
| 401  | Unauthenticated                |
| 403  | User does not have admin role  |
| 500  | Server error                   |
