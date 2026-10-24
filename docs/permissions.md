# Permissions

What users may do is decided by **abilities**: named things a role may do, such as `content.post.publish`. Each ability comes with default roles. Admins can change which roles have it, and extensions can add roles, abilities and rules of their own.

## Roles

| Role | Who |
|------|-----|
| `guest` | Everyone, signed in or not. Abilities granted to guests are granted to all. |
| `member` | Registered users, e.g. who comment |
| `editor` | Writing and publishing content |
| `admin` | Running the site: has every ability, except those marked strict |

Users have one or more roles (see [users](users.md#roles)). Extensions can add roles, e.g. `moderator`, and so can the site:

```bash
bin/console quire:permissions:create-role translator "Translator" --from=editor   # starts with an editor's abilities
bin/console quire:permissions:create-role translator "Übersetzer"                 # renames it
bin/console quire:permissions:delete-role translator                              # takes it from its users too
```

The site's own roles are kept in the setting `quire.permissions.roles`. Roles that Quire and extensions declare can't be deleted.

## Using the admin

`quire.admin.access` lets users open the [admin interface](admin.md): every page in the route group `admin`, whichever extension adds it. Editors have it by default, and admins have every ability.

## Abilities of content

Every content type has eight abilities, e.g. for posts:

| Ability | Default roles |
|---------|---------------|
| `content.post.view` | guest |
| `content.post.view_drafts` | editor |
| `content.post.create` | editor |
| `content.post.edit_own` | editor |
| `content.post.edit_any` | editor |
| `content.post.publish` | editor |
| `content.post.review` | editor |
| `content.post.delete` | editor |

On the site, they mean:

- **Viewing**: visitors who may not view a type are asked to sign in by the firewall (on the sign-in page, then back), and signed-in users who may not are refused (403). Lists and `quire_content()` queries leave out the types the viewer may not view. To make posts members-only:

  ```bash
  bin/console quire:permissions:revoke guest content.post.view
  bin/console quire:permissions:grant member content.post.view
  ```

- **Previewing**: those who may view a type's drafts see its unpublished items at their URL, with a notice that the page is a preview. Previews are never cached or indexed. Lists show published items only, even to editors.

- `edit_own` is granted only for items the user wrote.

- `review` is for approving [pending revisions](content.md#pending-revisions), or asking for changes to them. Quire checks that authors don't approve their own changes; admin interfaces check the ability.

## Changing them

```bash
bin/console quire:permissions:list                       # every ability, and the roles that have it
bin/console quire:permissions:list --role=member         # what members may do
bin/console quire:permissions:list --prefix=content.page.
bin/console quire:permissions:grant editor acme-reviews.moderate
bin/console quire:permissions:revoke editor content.page.delete
bin/console quire:permissions:reset content.page.delete  # back to its default roles
bin/console quire:permissions:reset                      # undo every change
```

In the [admin interface](admin.md#users-and-roles), "Roles" has a page per role, and shows what saving changes before it does.

Changes are kept as differences from the defaults, in the setting `quire.permissions.grants`. So an extension enabled later brings its own defaults, and changes made earlier stay. They're part of [backups](backups.md) like every setting.

## Why someone may or may not

```bash
bin/console quire:permissions:explain grace content.post.publish
```

It says whether the user has the ability and why: which of their roles have it, by default or because the site granted it, whether they have it as an admin, what the [rules](#rules) of extensions decide, and whether they're suspended. It decides as `is_granted()` does, without a particular item; for `edit_own`, and when rules decide case by case, the answer can differ per item. "Check access" in the admin does the same, and lists the roles that have an ability. In code, use `Quire\Permission\AccessExplainer`.

## Checking abilities

Use Symfony's security as usual. Quire's abilities work with `is_granted()` wherever it does:

```twig
{% if is_granted('content.post.edit_any', content) %}
    <a href="…">Edit</a>
{% endif %}
```

```php
#[IsGranted('acme-reviews.moderate')]
public function moderate(): Response

if ($this->security->isGranted('content.product.publish', $product)) {
```

Pass the item as the subject when there is one, so `edit_own` and the rules of extensions can look at it.

## Declaring them

Extensions declare roles, abilities and rules with `Extend\Permissions`:

```php
new Extend\Permissions()
    ->role('moderator', 'Moderator')
    ->ability('acme-reviews.moderate', 'Moderate reviews', roles: ['moderator', 'editor'])
    ->ability('acme-reviews.purge', 'Delete all reviews for good', strict: true)
    ->rule(Reviews\BannedReviewersRule::class),
```

- Ability names start with the extension's ID and a dot, like settings; the site's own start with `custom.`.
- The roles given are the defaults. Admins have every ability that isn't **strict**; a strict one must be granted to admins like to any role, e.g. for things that can't be undone.
- Roles are declared before the abilities that use them. An extension can give its abilities to roles of core or of extensions it requires.

### Rules

A rule decides abilities case by case, beyond roles: "organisers may edit the news of their own tournaments", or "nobody may edit a locked item". It's a service implementing `Quire\Permission\AbilityRuleInterface`:

```php
final class BannedReviewersRule implements AbilityRuleInterface
{
    public function decide(string $ability, ?UserInterface $user, mixed $subject): ?bool
    {
        if ('acme-reviews.write' === $ability && $user instanceof User && ($user->attributes['banned'] ?? false)) {
            return false;
        }

        return null;
    }
}
```

It returns `true` to grant, `false` to refuse, or `null` to leave it to roles and other rules. A refusal wins over everything, admins included. Rules are asked about every ability, so return `null` quickly for those they aren't about. `$user` is the user the firewall signed in: a Quire user, or the application's own when Quire uses its users (see [users](users.md#users-of-the-application-quire-is-installed-in)).

Plain Symfony voters work too, e.g. one registered by a host application. With Symfony's default strategy, any voter that grants an ability grants it; Quire's own refusals are decided in one vote, so a rule's refusal stays a refusal only among Quire's rules. Use the `unanimous` strategy if any voter's refusal should win.

## In code

`Quire\Permission\Permissions` tells what roles alone allow, and changes grants:

```php
$permissions->rolesWith('content.post.publish');   // ['editor']
$permissions->allows(['member'], 'content.post.view');
$permissions->grant('member', ['content.post.view_drafts']);
$permissions->abilities();                         // every ability, with label, group, default roles, strict
```
