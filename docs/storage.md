# Storage

Uploaded files, such as images, downloads and attachments, are kept on **storage disks**: a directory on a volume by default, or any S3-compatible service. Everything Quire knows about the files is in PostgreSQL, like the rest of the site.

Extensions declare the disks they need, and those disks work without any configuration. The site decides where each one actually is, so moving files to S3 later is a configuration change, not a code change.

## Disks

```bash
bin/console quire:storage:list     # every disk: public or private, where it is, who declared it, what it holds
bin/console quire:storage:check    # writes, reads and deletes a small file on each disk
```

- **Public disks** hold files for everyone, e.g. images in pages. They're served at `/storage/<disk>/…` (under Quire's prefix, when it has one), or from the bucket or a CDN.
- **Private disks** hold files only some people may have, e.g. downloads for members. They have no public URL: code checks who may have a file, then gives out a [signed link](#signed-links) that expires.

A disk that nothing configures is a directory under `storage/`: `storage/public/<disk>` or `storage/private/<disk>`. Quire's Docker setup keeps `storage/` on its own volume (`storage_data`), so files survive new containers and deployments. Elsewhere, put `storage/` on persistent storage, or set `quire.storage.root`.

## Putting a disk elsewhere

In `config/packages/quire.yaml`, by the disk's name:

```yaml
quire:
    storage:
        disks:
            acme-gallery:                       # declared by the acme/gallery extension
                adapter: s3
                bucket: my-site-media
                region: eu-central-1
                access_key: '%env(S3_ACCESS_KEY)%'
                secret_key: '%env(S3_SECRET_KEY)%'
                prefix: gallery                 # disks can share a bucket, each with its own prefix
                url: https://media.example.com/gallery   # optional: a CDN or the bucket's public domain
```

| Option | For | |
|--------|-----|-|
| `adapter` | all | `local` (the default), `s3`, or `service` |
| `public` | the site's own disks | Whether its files are for everyone. Extensions set it for theirs. |
| `url` | public disks | Base URL of the files, e.g. a CDN. By default Quire serves local disks itself, and S3 disks link to the bucket. |
| `path` | `local` | The directory |
| `bucket`, `region`, `prefix` | `s3` | |
| `endpoint`, `path_style` | `s3` | For services other than AWS: their address, and `path_style: true` for those that want the bucket in the path |
| `access_key`, `secret_key` | `s3` | Without them, the AWS SDK's usual sources apply: environment variables, instance roles |
| `service` | `service` | The ID of a Flysystem `FilesystemOperator` service, e.g. a storage of `league/flysystem-bundle` that an application already has |

**S3-compatible services** need the adapter package: `composer require league/flysystem-async-aws-s3`. This includes AWS S3, Cloudflare R2, Backblaze B2, Wasabi, and self-hosted SeaweedFS, Garage or RustFS. (MinIO's community edition has been archived since 2026 and isn't recommended.) For R2, use `endpoint: https://<account-id>.r2.cloudflarestorage.com`, and set `url` to the bucket's public domain, since R2 serves public files elsewhere than its API. Quire sends no ACLs, so who may read a bucket is up to its policy. A public disk's bucket must allow anonymous reads of its prefix.

The site can also add disks of its own, e.g. for files its own code keeps. Each disk needs a place of its own: two disks in the same directory, or the same bucket and prefix, are refused, because each would see the other's files.

## Storing files

Code stores files through `Quire\Storage\ObjectStore`. It names each file after its content and records it in the table `quire.storage_object`:

```php
$object = $objects->store('acme-gallery', $upload->getContent(), $upload->getMimeType());
$connection->insert('quire.acme_gallery_photo', ['object_id' => $object->id, 'caption' => $caption]);

$objects->url($object);                  // "/storage/acme-gallery/3a/7b/3a7b…e1.jpg"
$objects->url($object, absolute: true);  // with the site's address, e.g. for emails and feeds
```

- **Files are named after their content.** A file's key is the SHA-256 of its bytes, so the same file is stored once per disk, and a stored file never changes. Its URL can be cached for good: browsers and CDNs keep it for a year.
- **The type comes from the content.** Quire detects the file's type itself. The type the caller passes counts only when the content doesn't tell, e.g. for CSV, and is never trusted for HTML, SVG or scripts.
- **Refer to files by their object's ID**, with a foreign key to `quire.storage_object (id) ON DELETE RESTRICT`. Never refer to them by URL: URLs change when a disk moves, IDs don't.
- **Storing joins your transaction.** The object's row commits or rolls back with your own rows. The file itself is written first; if the transaction fails, the file is [collected](#cleaning-up) later.

`Quire\Storage\StorageDisks` gives each disk by name (`$disks->get('acme-gallery')`). Its `files` property is the disk's Flysystem filesystem, for files your code manages on its own.

### Signed links

Files on private disks are reached through links that expire. Check that the viewer may have the file first, then send them to the link:

```php
if ($this->security->isGranted('acme-gallery.download', $photo)) {
    return new RedirectResponse($objects->temporaryUrl($object, new \DateTimeImmutable('+10 minutes')));
}
```

For S3 disks, the link is signed by the service. Otherwise it's a Quire URL signed with the app's secret, and changing the secret invalidates all signed links.

## Serving files

In Quire's Docker setup, Caddy serves local public disks directly, with support for byte ranges (e.g. for seeking in videos). Without it, e.g. in an application Quire is embedded in, Quire serves them itself. Either way, every stored file is sent with:

- `Cache-Control: public, max-age=31536000, immutable`, because a stored file never changes.
- `X-Content-Type-Options: nosniff` and a `Content-Security-Policy` with `sandbox`. Uploaded files are someone else's content: a browser never treats them as another type, and an uploaded HTML or SVG file can't run scripts on the site.

Signed links are never cached.

## Cleaning up

Files are never deleted directly. Delete the rows that refer to an object, and the garbage collector deletes the object and its file:

```bash
bin/console quire:storage:gc --dry-run         # lists what would be deleted
bin/console quire:storage:gc                   # deletes what has been unused for a day
bin/console quire:storage:gc --grace="7 days"
```

It deletes two kinds of garbage, both only after the grace period:

- **Objects that nothing refers to.** It finds out what refers to objects from PostgreSQL itself: every foreign key to `quire.storage_object`, in core's tables and every extension's, counts.
- **Files on a disk that no object records**, e.g. from an upload whose transaction was rolled back.

Storing the same content again restarts an object's grace period. Run the collector regularly, e.g. daily from cron. Choose a grace period at least as long as the time you might want to restore an older database backup, because the files it refers to must still be there.

## Backups

`quire:backup` copies the files of local disks with the database, checked against their checksums, and `quire:restore` puts them back on the disks, wherever they are by then. Files on S3 disks are copied with `--files=all`; otherwise turn on versioning or replication for their buckets. See [backups](backups.md#which-files).
