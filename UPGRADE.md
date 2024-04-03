# Upgrade Guide

With every upgrade, make sure to re-publish Chatsys's assets:

## For v1.2.3 and earlier versions

```
php artisan verndor:publish --tag=Chatsys-views --force
php artisan verndor:publish --tag=Chatsys-assets --force
```

If needed, you can re-publish the other assets the same way above by just replacing the name of the asset (Chatsys-NAME).

## For v1.2.4+ and higher vertions

To re-publish only `views` & `assets`:

```
php artisan Chatsys:publish
```

To re-publish all the assets (views, assets, config..):

```
php artisan Chatsys:publish --force
```

> This will overwrite all the assets, so all your changes will be overwritten.
