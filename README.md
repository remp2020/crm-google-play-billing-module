# Google Play Billing Module

## Installation

We recommend using Composer for installation and update management. To add CRM GooglePlay Billing extension to your [REMP CRM](https://github.com/remp2020/crm-skeleton/) application use following command:

```bash
composer require remp/crm-google-play-billing-module
```

Enable installed extension in your `app/config/config.neon` file:

```neon
extensions:
	# ...
	- Crm\GooglePlayBillingModule\DI\GooglePlayBillingModuleExtension
```

Add database tables and seed GooglePlay Billing payment gateway and its configuration:

```bash
php bin/command.php phinx:migrate
php bin/command.php application:seed
```

## Configuration

### Payment notifications

To be able to read pub/sub notifications generated by your payments, CRM needs to be able to have an access to your Pub/Sub notifications account via service account credentials.

Head to the [Google Developer Console](https://console.developers.google.com/) - Credentials and generate new Service account JSON key and download it.

Make sure you're generating the key for the same project and same service account, that's linked to your [Google Play console](https://play.google.com/apps/publish/) - Developer account - API access.

Enter path to service account JSON with access to Google Play into CRM configuration:

   - Visit to CRM admin settings _(gear icon)_.
   - Select **Payments** category.
   - Enter path to **Google Play Service Account** key.


## Mapping subscriptions

GooglePlay's in-app subscription is mapped to CRM subscription type via relation table `google_play_billing_subscription_types`.

We'll be adding administration interface later. For now it's needed to seed these mapping data manually.


## Matching payments with CRM users

GooglePlay's in-app subscription is mapped to CRM users via `user_id` field provided within `DeveloperPayload` of `SubscriptionResponse`.

This can be overrided by implementing `SubscriptionResponseProcessorInterface` and initializing own implementation as `subscriptionResponseProcessor` in config:

```neon
subscriptionResponseProcessor: Crm\FooModule\GoogleBillingSubscriptionResponse\MyFooBarSubscriptionResponseProcessor
```

## Support

- Only version 1.0 of DeveloperNotification is supported.
- Only version 1.0 of SubscriptionNotification is supported.
- One-time products are not supported.
- Free trial is accepted without checking if user _(MUID)_ used trial already. We rely on Google; it shouldn't allow multiple free trials for one credit card.
