=== RH Sync ===
Contributors: robinherbeck
Tags: sync, migration, staging, database, deployment
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.5
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

= 0.4.5 =
* Adaptive download block size: some hosts (mod_fcgid/mod_security) kill the source PHP process once a response exceeds a size limit ("Empty reply", no PHP fatal), which made pull impossible on those servers. The client now halves the download block size automatically when a block is rejected (down to a minimum) and continues from the same offset, so the download settles on a size the server accepts, no server config needed. The progress window shows when the block size is reduced.

= 0.4.4 =
* Fixes a fatal on shared hosts where set_time_limit is blocked via disable_functions: the download handler died before sending any bytes (client saw "Empty reply"), making pull impossible. The call is now guarded with function_exists.
* A transient read miss on the backup file (race against the still-writing export, NFS latency) no longer deletes the download token. It returns 503 instead of 404, so the download retries can actually recover.
* Download errors now carry the real reason into the log (peer-side fatal on "empty reply", and token_expired vs. file_missing which are both 404) instead of just "HTTP status 404".

= 0.4.3 =
* New: an opt-in checkbox "Allow HTTP (unencrypted)" in the peer setup dialogs lets you add a peer over HTTP when the other side has no HTTPS. Off by default with a clear warning; the HTTPS requirement stays the default. The SSRF guard (private/reserved target IPs) still applies even with the opt-in set. Also fixes the RHSYNC_VERSION constant that was left at 0.4.1.

= 0.4.2 =
* Fixes a fatal error on shared hosts where disk_free_space is blocked via disable_functions (e.g. Confixx/twosteps). The preflight check now guards the call with function_exists; when the function is unavailable, free disk space is reported as unknown and the sync is not blocked.

= 0.4.1 =
* Bundles db-engine 1.1.3: fixes a backup import that aborted with "no db_prefix" when the media library contained a file named manifest.json (e.g. from Really Simple SSL). The unpacker now matches db.sql and manifest.json by full path instead of filename, so a same-named upload no longer overwrites the real manifest.

= 0.4.0 =
* Resumable chunked download: large uploads no longer abort mid-transfer. The pull download now uses HTTP range requests with a byte-offset cursor that survives across ticks, so a dropped connection only costs the current chunk and the next tick resumes.
* Sync history now records every run (pull and push) in the tick-based path. Previously the log stayed empty because completed jobs never wrote an entry.
* Orphaned finished job states are garbage-collected after a grace period instead of piling up as stale options.

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
