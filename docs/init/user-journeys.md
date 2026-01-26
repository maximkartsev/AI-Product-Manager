# User Journeys

This document describes the user interaction flows extracted from the Product Concept Document.
Each journey maps user actions to the database entities and fields involved.

---

## Table of Contents

1. [Freemium Video Effect Application](#freemium-video-effect-application)
2. [User Subscription Upgrade](#user-subscription-upgrade)
3. [Viral Loop Onboarding](#viral-loop-onboarding)
4. [Publish Video to Public Gallery](#publish-video-to-public-gallery)
5. [Loyalty Credit Accumulation and Redemption](#loyalty-credit-accumulation-and-redemption)

---

## Freemium Video Effect Application

A new or existing free user discovers an effect, signs up, uploads a video, applies the AI effect, and downloads the result with a mandatory animated watermark.

### Steps & SQL Queries

#### Step 1: User lands on the website and browses the gallery of available AI effects.

- **Operation:** READ on `effects`
- **Fields:** `id`, `name`, `slug`, `thumbnail_url`, `is_premium`, `is_active`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, slug, thumbnail_url, is_premium FROM effects WHERE is_active = TRUE;
```

#### Step 2: User selects a specific effect, navigating to its detail page to see a preview.

- **Operation:** READ on `effects`
- **Fields:** `id`, `name`, `slug`, `description`, `thumbnail_url`, `preview_video_url`, `is_premium`, `is_active`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, slug, description, thumbnail_url, preview_video_url, is_premium FROM effects WHERE id = ? AND is_active = TRUE;
```

#### Step 3: User signs up or logs in using social login or email.

- **Operation:** CREATE on `users`
- **Fields:** `name`, `email`, `password_hash_or_social_id`, `social_provider`, `authentication_token`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO users (name, email, password_hash_or_social_id, social_provider, authentication_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW());
```

#### Step 4: After successful authentication, the user is returned to the upload flow and selects a video file from their device.

- **Operation:** CREATE on `files`
- **Fields:** `user_id`, `url`, `path`, `disk`, `mime_type`, `size`, `original_filename`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO files (user_id, url, path, disk, mime_type, size, original_filename, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW());
```

#### Step 5: The user initiates the AI processing, and a progress indicator is displayed.

- **Operation:** CREATE on `videos`
- **Fields:** `user_id`, `effect_id`, `original_file_id`, `status`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO videos (user_id, effect_id, original_file_id, status, created_at, updated_at) VALUES (?, ?, ?, 'processing', NOW(), NOW());
```

#### Step 6: The system presents the processed video on the Result Page, featuring the applied AI effect and a default animated watermark.

- **Operation:** READ on `videos`
- **Fields:** `videos.id`, `videos.status`, `videos.user_id`, `videos.processed_file_id`, `files.url`
- **Status:** ✅ Verified against schema

```sql
SELECT v.id, v.status, f.url FROM videos AS v JOIN files AS f ON v.processed_file_id = f.id WHERE v.id = ? AND v.user_id = ?;
```

#### Step 7: User customizes the watermark using free options (e.g., choosing a different free style, adjusting opacity within free limits).

- **Operation:** READ on `watermarks`
- **Fields:** `id`, `name`, `asset_url`, `preview_url`, `customization_options`, `is_premium`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, asset_url, preview_url, customization_options FROM watermarks WHERE is_premium = FALSE;
```

#### Step 8: User clicks 'Download' to save the final, watermarked video file to their device.

- **Operation:** CREATE on `exports`
- **Fields:** `user_id`, `video_id`, `status`, `settings`, `watermark_removed`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO exports (user_id, video_id, status, settings, watermark_removed, created_at, updated_at) VALUES (?, ?, 'pending', ?, FALSE, NOW(), NOW());
```

### Entities Involved

- **User**
- **Effect**
- **File**
- **Video**
- **Watermark**

### Required Fields

- `User.email`
- `User.authentication_token`
- `Effect.id`
- `File.url`
- `File.user_id`
- `Video.user_id`
- `Video.effect_id`
- `Video.original_file_id`
- `Video.processed_file_id`
- `Watermark.id`

---

## User Subscription Upgrade

A free user attempts a premium action, is prompted to upgrade, completes a payment, and successfully downloads a clean, non-watermarked video.

### Steps & SQL Queries

#### Step 1: On the Result Page, a free user attempts a premium action, such as setting the watermark opacity to 0% or selecting a premium watermark design.

- **Operation:** READ on `watermarks`
- **Fields:** `id`, `is_premium`
- **Status:** ✅ Verified against schema

```sql
SELECT is_premium FROM watermarks WHERE id = ?;
```

#### Step 2: The system intercepts the action and displays a payment modal highlighting the benefits of upgrading.

- **Operation:** READ on `tiers`
- **Fields:** `id`, `name`, `description`, `price_monthly`, `price_yearly`, `features`, `is_active`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, description, price_monthly, price_yearly, features FROM tiers WHERE is_active = TRUE;
```

#### Step 3: The user selects a subscription tier or a one-time purchase package from the modal.

- **Operation:** CREATE on `purchases`
- **Fields:** `user_id`, `package_id`, `total_amount`, `status`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO purchases (user_id, package_id, total_amount, status, created_at, updated_at) VALUES (?, ?, ?, 'pending', NOW(), NOW());
```

#### Step 4: The user is redirected to a third-party payment processor (e.g., Stripe) to enter payment details.

- **Operation:** READ on `purchases`
- **Fields:** `id`
- **Status:** ✅ Verified against schema

```sql
SELECT id FROM purchases WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1;
```

#### Step 5: After successful payment, the processor sends a confirmation webhook to the application's backend.

- **Operation:** CREATE on `payments`
- **Fields:** `user_id`, `purchase_id`, `transaction_id`, `status`, `amount`, `currency`, `payment_gateway`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO payments (user_id, purchase_id, transaction_id, status, amount, currency, payment_gateway, processed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW());
```

#### Step 6: The system processes the webhook, creates a Subscription or Purchase record, and updates the User's account status to premium.

- **Operation:** CREATE on `subscriptions`
- **Fields:** `user_id`, `tier_id`, `package_id`, `external_id`, `status`, `period_start`, `period_end`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO subscriptions (user_id, tier_id, package_id, external_id, status, period_start, period_end, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, ?, NOW(), NOW());
```

#### Step 7: The user is redirected back to the Result Page, where premium features are now unlocked.

- **Operation:** READ on `subscriptions`
- **Fields:** `user_id`, `status`, `period_end`
- **Status:** ✅ Verified against schema

```sql
SELECT status FROM subscriptions WHERE user_id = ? AND status = 'active' AND period_end > NOW() ORDER BY created_at DESC LIMIT 1;
```

#### Step 8: The user removes the watermark completely and clicks 'Download'.

- **Operation:** CREATE on `exports`
- **Fields:** `user_id`, `video_id`, `status`, `settings`, `watermark_removed`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO exports (user_id, video_id, status, settings, watermark_removed, created_at, updated_at) VALUES (?, ?, 'pending', ?, TRUE, NOW(), NOW());
```

#### Step 9: The system provides the high-resolution, watermark-free video file for download.

- **Operation:** UPDATE on `exports`
- **Fields:** `id`, `status`, `exported_file_id`
- **Status:** ✅ Verified against schema

```sql
UPDATE exports SET status = 'completed', exported_file_id = ? WHERE id = ?;
```

### Entities Involved

- **User**
- **Video**
- **Watermark**
- **Tier**
- **Package**
- **Subscription**
- **Purchase**
- **Payment**

### Required Fields

- `User.id`
- `Video.id`
- `Tier.id`
- `Package.id`
- `Payment.transaction_id`
- `Payment.status`
- `Payment.user_id`
- `Subscription.user_id`
- `Subscription.tier_id`
- `Subscription.status`
- `Purchase.user_id`
- `Purchase.package_id`

---

## Viral Loop Onboarding

A new user discovers a video on social media with the platform's watermark, follows the link to the specific effect page, and signs up to create their own video.

### Steps & SQL Queries

#### Step 1: The user lands directly on the Effect Detail Page corresponding to the effect seen in the video.

- **Operation:** READ on `effects`
- **Fields:** `id`, `name`, `description`, `preview_video_url`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, description, preview_video_url FROM effects WHERE id = ?;
```

#### Step 2: The user signs up, and upon successful registration, is immediately taken to the video upload step for the pre-selected effect.

- **Operation:** CREATE on `users`
- **Fields:** `email`, `password_hash_or_social_id`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO users (email, password_hash_or_social_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW());
```

### Entities Involved

- **Effect**
- **User**

### Required Fields

- `Effect.id`
- `User.email`
- `User.password_hash_or_social_id`

---

## Publish Video to Public Gallery

An authenticated user shares their processed video to the platform's public 'Explore' gallery for other users to discover.

### Steps & SQL Queries

#### Step 1: After processing a video, the user is on the Result Page.

- **Operation:** READ on `videos`
- **Fields:** `id`, `user_id`
- **Status:** ✅ Verified against schema

```sql
SELECT * FROM videos WHERE id = ? AND user_id = ?;
```

#### Step 2: The system creates a 'GalleryVideo' record, making the video visible in the public gallery.

- **Operation:** CREATE on `gallery_videos`
- **Fields:** `user_id`, `video_id`, `title`, `tags`, `is_public`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO gallery_videos (user_id, video_id, title, tags, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, TRUE, NOW(), NOW());
```

### Entities Involved

- **User**
- **Video**
- **GalleryVideo**
- **Tag**

### Required Fields

- `User.id`
- `Video.id`
- `GalleryVideo.title`
- `GalleryVideo.user_id`
- `GalleryVideo.video_id`
- `GalleryVideo.is_public`
- `Tag.name`

---

## Loyalty Credit Accumulation and Redemption

A user earns discount credits by creating free videos and later redeems these credits to get a discount on a premium purchase.

### Steps & SQL Queries

#### Step 1: A free user successfully processes a video.

- **Operation:** UPDATE on `videos`
- **Fields:** `status`, `id`, `user_id`
- **Status:** ✅ Verified against schema

```sql
UPDATE videos SET status = 'processed' WHERE id = ? AND user_id = ?;
```

#### Step 2: The system automatically awards loyalty credits by creating a 'CreditTransaction' record linked to the user's account.

- **Operation:** CREATE on `credit_transactions`
- **Fields:** `user_id`, `amount`, `type`, `description`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO credit_transactions (user_id, amount, type, description, created_at, updated_at) VALUES (?, ?, 'credit', 'Loyalty credit for video creation', NOW(), NOW());
```

#### Step 3: The user's accumulated discount balance is displayed on their account dashboard.

- **Operation:** READ on `users`
- **Fields:** `discount_balance`, `id`
- **Status:** ✅ Verified against schema

```sql
SELECT discount_balance FROM users WHERE id = ?;
```

#### Step 4: Later, the user decides to purchase a premium package (e.g., '5 watermark-free exports').

- **Operation:** READ on `packages`
- **Fields:** `id`, `name`, `price`
- **Status:** ✅ Verified against schema

```sql
SELECT id, name, price FROM packages WHERE id = ?;
```

#### Step 5: On the checkout page, the system displays the full price and the available discount from the user's credit balance.

- **Operation:** READ on `users`
- **Fields:** `discount_balance`, `id`
- **Status:** ✅ Verified against schema

```sql
SELECT discount_balance FROM users WHERE id = ?;
```

#### Step 6: The user chooses to apply their credits, which reduces the final amount due.

- **Operation:** READ on `users`
- **Fields:** `discount_balance`, `id`
- **Status:** ✅ Verified against schema

```sql
SELECT discount_balance FROM users WHERE id = ?;
```

#### Step 7: The user completes the payment for the remaining balance.

- **Operation:** CREATE on `purchases`
- **Fields:** `user_id`, `package_id`, `original_amount`, `applied_discount_amount`, `total_amount`, `status`, `created_at`, `updated_at`
- **Status:** ✅ Verified against schema

```sql
INSERT INTO purchases (user_id, package_id, original_amount, applied_discount_amount, total_amount, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW());
```

#### Step 8: The system records the 'Purchase' and creates a new 'CreditTransaction' to debit the redeemed credits from the user's balance.

- **Operation:** UPDATE on `purchases`
- **Fields:** `status`, `external_transaction_id`, `processed_at`, `id`
- **Status:** ✅ Verified against schema

```sql
UPDATE purchases SET status = 'completed', external_transaction_id = ?, processed_at = NOW() WHERE id = ?;
```

### Entities Involved

- **User**
- **Video**
- **Discount**
- **CreditTransaction**
- **Package**
- **Purchase**
- **Payment**

### Required Fields

- `User.id`
- `User.discount_balance`
- `CreditTransaction.user_id`
- `CreditTransaction.amount`
- `CreditTransaction.type`
- `Package.id`
- `Purchase.user_id`
- `Purchase.package_id`
- `Purchase.applied_discount_amount`
- `Payment.purchase_id`

---

