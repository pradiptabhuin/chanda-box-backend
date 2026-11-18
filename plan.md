BASE URL = https://chanda.feelathomecare.net/api/v1
# Database Table design for ChandaBox app:
We will use SQL database (MySQL) here. Below is the table with relation.

Conventions used below:
* `pk` = `bigint unsigned auto_increment` (Laravel `$table->id()`).
* `fk -> table.id` = `bigint unsigned`, foreign key. `cascade` / `restrict` / `null` = on-delete rule.
* Money is stored in **rupees** as `decimal(12,2)` (the API sends rupees). Convert to paise only when calling Razorpay.
* Phones are stored normalised: 10-digit Indian mobile, no `+91`.
* Every table has `created_at` / `updated_at` (datetime) unless noted.
* "enum" columns can be MySQL `enum` or `varchar(20)` backed by a PHP enum (preferred, easier to extend).


*** Tables: ***

## 1. users:
Everyone who registers. Event owners and members are both just users.
* id: pk
* first_name: varchar(250)
* middle_name: varchar(250), nullable
* last_name: varchar(250), nullable
* profile_pic: varchar(250), nullable
* email: varchar(250), unique, nullable
* phone: varchar(10), unique
* password: varchar(255) (bcrypt hash)
* phone_verified_at: datetime, nullable (set when OTP verified; OTP is off for now)
* email_verified_at: datetime, nullable
* status: bool(1), default 1 (1 = active, 0 = disabled)
* remember_token: varchar(100), nullable
* created_at: datetime
* updated_at: datetime
* disabled_at: datetime, nullable (when status was set to 0)

> Note: the current migration and the API (`User = { id, name, ... }`, register body `{ name, ... }`) use a single `name` column.
> If we keep first/middle/last, the API must split `name` on register/update and return `name` = the joined parts.

## 2. personal_access_tokens: (Laravel Sanctum, already migrated)
Access token (1 day) and refresh token (60 days) per device login.
* id: pk
* tokenable_type: varchar(255) (`App\Models\User`)
* tokenable_id: bigint unsigned (-> users.id)
* name: text (device name)
* token: varchar(64), unique (sha256 hash)
* abilities: text, nullable (`access` or `refresh`)
* last_used_at: datetime, nullable
* expires_at: datetime, nullable, index
* created_at, updated_at

## 3. device_tokens:
FCM push tokens (`POST /me/device-token`). Reserved for later; the app polls notifications for now.
* id: pk
* user_id: fk -> users.id, cascade
* token: varchar(255), unique
* platform: enum('android', 'ios', 'web')
* last_seen_at: datetime, nullable
* created_at, updated_at

## 4. events:
A chanda (collection drive), e.g. "Sarbojanin Durga Puja".
* id: pk
* owner_id: fk -> users.id, restrict (an owner with events cannot be hard-deleted)
* name: varchar(250)
* description: text, nullable
* location: varchar(250), nullable
* proposed_amount: decimal(12,2) (suggested contribution per member)
* target_amount: decimal(12,2), nullable (overall budget)
* start_date: datetime
* end_date: datetime, nullable
* status: enum('active', 'closed', 'cancelled'), default 'active'
* created_at, updated_at
* deleted_at: datetime, nullable (soft delete: keep history of money collected)
* index: (owner_id), (start_date)

> `total_collected`, `member_count`, `contributor_count`, `my_contribution` are **computed** from
> `contributions` / `event_members`, not stored. (Add cached columns later only if it gets slow.)

## 5. event_schedules:
The event's programme / curriculum (`curriculum: [{ title, date, description }]`).
Replaced as a whole when the event is updated.
* id: pk
* event_id: fk -> events.id, cascade
* title: varchar(250)
* scheduled_at: datetime
* description: text, nullable
* sort_order: smallint unsigned, default 0
* created_at, updated_at
* index: (event_id, sort_order)

> Alternative: a `curriculum` JSON column on `events`. The child table is easier to query and validate.

## 6. event_members:
Membership **and** invitations in one table. One row per (event, phone).
The owner also gets a row (`role = owner`, `status = joined`) when the event is created.
* id: pk (this is the invitation id used by `/invitations/{id}/accept`)
* event_id: fk -> events.id, cascade
* user_id: fk -> users.id, null; nullable (null = invited phone not registered yet; filled in when that phone registers)
* phone: varchar(10) (the invited number)
* role: enum('owner', 'member'), default 'member'
* status: enum('invited', 'joined', 'declined', 'removed'), default 'invited'
* invited_by: fk -> users.id, null; nullable
* invited_at: datetime, nullable
* responded_at: datetime, nullable (accepted / declined time)
* added_at: datetime, nullable (when status became joined)
* created_at, updated_at, left_at
* unique: (event_id, phone)
* index: (user_id, status), (phone, status)

## 7. payments:
One row per payment attempt (Razorpay order + UPI QR). Created with status `created`.
* id: pk
* event_id: fk -> events.id, restrict
* user_id: fk -> users.id, restrict (the payer)
* amount: decimal(12,2)
* currency: char(3), default 'INR'
* razorpay_order_id: varchar(50), unique
* razorpay_qr_id: varchar(50), unique, nullable
* razorpay_payment_id: varchar(50), unique, nullable (set when paid)
* razorpay_signature: varchar(255), nullable
* qr_payload: text, nullable (UPI string shown as QR)
* qr_image_url: varchar(500), nullable
* method: enum('upi_qr', 'razorpay'), nullable (how it was actually paid)
* status: enum('created', 'paid', 'failed', 'expired'), default 'created'
* expires_at: datetime (QR close_by, now + 15 min)
* paid_at: datetime, nullable
* failure_reason: varchar(255), nullable
* created_at, updated_at
* index: (event_id, status), (user_id), (status, expires_at)

## 8. contributions:
A successful payment to an event. Inserted in the same DB transaction that marks the payment `paid`.
* id: pk
* event_id: fk -> events.id, restrict
* user_id: fk -> users.id, restrict
* payment_id: fk -> payments.id, restrict; unique (one contribution per payment, makes capture idempotent)
* amount: decimal(12,2)
* method: enum('upi_qr', 'razorpay')
* payment_ref: varchar(50) (Razorpay payment id, shown in the app)
* note: varchar(250), nullable
* paid_at: datetime
* created_at, updated_at
* index: (event_id, paid_at), (user_id, event_id)

## 9. bank_accounts:
Where an event owner withdraws money to.
* id: pk
* user_id: fk -> users.id, cascade
* holder_name: varchar(250)
* account_number: text (**encrypted** with Laravel `encrypted` cast; API always returns it masked)
* account_last4: char(4) (for masking/display without decrypting)
* ifsc: char(11) (validated `^[A-Z]{4}0[A-Z0-9]{6}$`)
* bank_name: varchar(250)
* upi_id: varchar(100), nullable
* is_primary: bool(1), default 0
* razorpay_contact_id: varchar(50), nullable (RazorpayX contact)
* razorpay_fund_account_id: varchar(50), nullable (RazorpayX fund account)
* created_at, updated_at
* deleted_at: datetime, nullable (soft delete: old withdrawals still point to it)
* index: (user_id, is_primary)

## 10. withdrawals:
A payout request from the wallet to a bank account (RazorpayX Payouts).
* id: pk
* user_id: fk -> users.id, restrict
* bank_account_id: fk -> bank_accounts.id, restrict
* amount: decimal(12,2) (min 100)
* status: enum('pending', 'processing', 'processed', 'reversed', 'failed'), default 'pending'
* razorpay_payout_id: varchar(50), unique, nullable
* utr: varchar(50), nullable (bank reference once processed)
* failure_reason: varchar(255), nullable
* requested_at: datetime
* processed_at: datetime, nullable
* created_at, updated_at
* index: (user_id, status)

## 11. wallet_transactions:
The ledger. Every rupee in or out of a user's wallet is one row. Never update the amount, only the status.
* id: pk
* user_id: fk -> users.id, restrict (wallet owner)
* type: enum('credit', 'debit')
* status: enum('success', 'pending', 'failed')
* amount: decimal(12,2) (always positive)
* title: varchar(250) (e.g. payer name, or "Withdrawal to HDFC ••4321")
* subtitle: varchar(250), nullable (e.g. event name)
* source_type: varchar(50) (`contribution` or `withdrawal`)
* source_id: bigint unsigned (-> contributions.id or withdrawals.id)
* created_at, updated_at
* unique: (source_type, source_id, type) (no double credit for one contribution)
* index: (user_id, created_at)

> Wallet balance is computed, not stored:
> `balance = credits(success) − debits(success) − debits(pending)`.
> To stop double withdrawals, lock the user's row (`SELECT ... FOR UPDATE` on `users`) while checking balance and inserting the pending debit.

## 12. notifications: (Laravel database notifications)
In-app inbox polled by the app every 30 s.
* id: uuid, pk
* type: varchar(255) (notification class)
* notifiable_type: varchar(255) (`App\Models\User`)
* notifiable_id: bigint unsigned (-> users.id)
* data: json (`{ type: "invitation" | "invitation_accepted" | "payment_received" | "withdrawal", title, body, invitation_id?, event_id?, ... }`)
* read_at: datetime, nullable
* created_at, updated_at
* index: (notifiable_type, notifiable_id, created_at)

## 13. webhook_events:
Log of incoming Razorpay webhooks. Lets us ignore duplicates and debug payment issues.
* id: pk
* provider: varchar(20), default 'razorpay'
* event_id: varchar(100), unique (Razorpay `x-razorpay-event-id`)
* event_type: varchar(100) (`payment.captured`, `qr_code.credited`, `payout.processed`, `payout.reversed`, ...)
* payload: json
* processed_at: datetime, nullable
* error: text, nullable
* created_at, updated_at


*** Laravel framework tables (already migrated, no changes needed): ***
* password_reset_tokens, sessions (from the users migration)
* cache, cache_locks (OTP codes and pending registrations are kept in cache, 10 min TTL)
* jobs, job_batches, failed_jobs (queued payouts, notifications)


*** Relations: ***

```
users 1 ──< events                (events.owner_id)
users 1 ──< event_members         (event_members.user_id, nullable until the phone registers)
users 1 ──< event_members         (event_members.invited_by)
events 1 ──< event_schedules
events 1 ──< event_members        (unique event_id + phone)
events 1 ──< payments
events 1 ──< contributions
users 1 ──< payments              (payer)
payments 1 ── 1 contributions     (contributions.payment_id unique)
users 1 ──< contributions         (payer)
users 1 ──< bank_accounts
users 1 ──< withdrawals
bank_accounts 1 ──< withdrawals
users 1 ──< wallet_transactions   (wallet owner = event owner for credits)
contributions 1 ── 1 wallet_transactions (credit to the event owner)
withdrawals 1 ── 1 wallet_transactions   (debit from the owner)
users 1 ──< notifications         (polymorphic notifiable)
users 1 ──< device_tokens
users 1 ──< personal_access_tokens (polymorphic tokenable)
```

*** Money flow (for reference): ***
1. Member starts paying → `payments` row (`created`).
2. Razorpay confirms (verify API or webhook) → in one transaction: lock payment, mark `paid`,
   insert `contributions`, insert `wallet_transactions` **credit** for the event owner, notify owner.
3. Owner withdraws → insert `withdrawals` (`pending`) + `wallet_transactions` **debit** (`pending`).
4. RazorpayX webhook → `processed`: debit `success`; `reversed`/`failed`: debit `failed` (money back in balance).
