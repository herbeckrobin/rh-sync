=== RH Sync ===
Contributors: robinherbeck
Tags: sync, migration, staging, database, deployment
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync database and uploads between two WordPress instances. Encrypted via HMAC, with fine-grained permissions per peer.

== Description ==

RH Sync syncs content between two WordPress installations, for example between local development, staging and production. You pair two instances once, then pull or push database and uploads between them.

The pairing runs directly between your sites over the WordPress REST API. There is no central server and no third party in between. Every request is signed with HMAC-SHA256, runs over HTTPS only and is protected against replay and SSRF attacks.

= Features =

* Pair two WordPress instances, via a pairing code or manual entry
* Pull and push database and uploads between the paired sites
* Sync profiles: decide per peer which data is transferred (content, taxonomies, comments, users, options, custom tables, uploads)
* Permissions per peer, separated for inbound and outbound: what the peer may trigger on your site, and what you may trigger on theirs
* Safe defaults: on production environments inbound access is disabled by default

= Security =

* Every request signed with HMAC-SHA256, verified server-side
* HTTPS enforced on all peer endpoints
* Protection against replay and SSRF attacks
* Inbound permissions are enforced server-side, not only in the interface
* Every admin action is guarded by a capability check and a nonce

= Part of the rh-blueprint collection =

RH Sync belongs to a family of small, focused plugins by Robin Herbeck. It runs on its own and needs no other module. Several plugins in the collection share the same interface and settings system.

== Installation ==

1. Install the plugin on both sites you want to sync.
2. Activate it.
3. On the first site, open RH Blueprint -> Sync and create a connection. The code carries the address of that site.
4. On the second site, enter the code to complete the pairing.
5. Set the permissions and sync profile per peer, then start a pull or push.

== Frequently Asked Questions ==

= Do I need an external service? =

No. RH Sync connects your two sites directly. There is no central server and no third party.

= Is the transfer secure? =

Yes. Every request is signed with HMAC-SHA256, runs over HTTPS and is protected against replay and SSRF attacks. Inbound rights are enforced server-side.

= Can a paired site simply overwrite everything on mine? =

No. You decide per peer, separately for inbound and outbound, what is allowed. On production environments inbound access is disabled by default.

= Which data is transferred? =

You control that through the sync profile per peer: content, taxonomies, comments, users, options, custom tables and uploads can be toggled individually.

= Do I also need RH Backup? =

No, RH Sync runs on its own. RH Backup is the sister plugin for local backups of a single site.

== Changelog ==

= 0.3.2 =
* First release in the WordPress plugin directory.
* Peer-to-peer sync of database and uploads between two WordPress instances.
* Pairing via pairing code or manual entry.
* Sync profiles and separate permissions per peer (inbound/outbound).
* Secured with HMAC-SHA256, enforced HTTPS, plus replay and SSRF protection.
* Clean interface in the native WordPress style.

== Upgrade Notice ==

= 0.3.2 =
First release in the WordPress plugin directory.
