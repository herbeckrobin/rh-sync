=== RH Sync ===
Contributors: robinherbeck
Tags: sync, migration, staging, database, deployment
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.8.0
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

= 0.8.0 =
* Fix: two drivers can no longer work on the same run at once. This is the fix for the incident of 2026-08-02, now covered by a test.
* Change: a step that only polls waits at least two seconds before the next one. Without it a running import fired hundreds of requests per minute at the other site, which is what trips a rate limit or a WAF on shared hosting.
* Internal: shared building blocks from core 2.6.0, tick engine 1.1.0.

= 0.7.1 =
* Same content as 0.7.0, released under a new number. A build carrying the version 0.7.0 had already been installed on some sites before that version was published, so those sites were never offered the update. If you are on 0.7.0, this is the release you want.

= 0.7.0 =
* A sync no longer carries its own pairing to the other side. Peer list, history and running jobs describe the site they belong to, so they stay there. Previously a pull could leave the target with the source's peer list, pointing the connection at the wrong site.
* The protection of a site's own settings is now verified instead of assumed. Every write is checked and read back, and a failed protection stops the import before anything goes live rather than reporting success.
* A peer whose address is this site itself is rejected when the connection is created, with an explanation.
* After an import, tables the source uses but this site has never had are named in the summary, for example the queue tables of WooCommerce. They are not created: that is the job of the plugin that needs them.
* Scheduled posts keep their publishing date through a sync. Until now the posts arrived but their timers did not, because the target site keeps its own scheduling data during an import. Scheduled posts stayed scheduled forever and never appeared.
* A post whose publishing date has already passed is reported by name instead of being published. A sync never publishes anything on its own.
* Pending trackbacks and leftover import cleanups get their timers back as well, and timers pointing at posts that no longer exist are removed.
* The summary and the history now say what was restored and what needs a decision.
* New command line tools for checking and repairing a site: see wp help rh sync. Among them "wp rh sync schedule" to see which timers are missing and "wp rh sync schedule-repair" to restore them without waiting for the next sync.
* A sync that does not include settings no longer writes to the settings table at all. Previously it logged database errors and rewrote rows it had never touched.
* Requires db-engine 1.4.0, which is bundled. Both sites need this version before a sync stops carrying its own pairing across.

= 0.6.1 =
* Fixes an import that could fail on tables carrying named foreign keys, as used by some plugins. Such tables are now built without their foreign keys, which are re-applied once the new data is live.
* An import that fails before anything went live now says so plainly instead of warning about a manual restore. Nothing was changed, so there is nothing to restore.
* Tables left behind by a run that was killed are cleaned up on the next run and by the scheduled check.
* Requires db-engine 1.3.1, which is bundled.

= 0.6.0 =
* An interrupted import no longer leaves the receiving site broken. The import now builds its tables alongside the live ones and switches over in a single atomic step at the very end. If anything goes wrong before that moment, the site simply keeps running on its previous data and nothing has to be repaired.
* The site's own settings (address, active plugins, user roles, peer list) are written into the new tables before the switch instead of being restored afterwards, so there is no longer a window in which the site carries the source site's identity.
* User role definitions are now preserved. Previously they could be lost during an import, which left every account without permissions and the admin area unreachable.
* Every run keeps a log file that survives a crash. If an import is killed by a memory limit, a time limit or the web server, the log still shows where it was and how much memory it was using.
* A push now reports a stalled run as stalled. The progress used to be driven by this site's own clock and could show "running" for a long time after the receiving site had stopped responding.
* If the database cannot do the atomic switch, the import falls back to the previous behaviour and, for that case only, places a short-lived recovery page that can restore the site's own settings without WordPress being able to start.
* Requires db-engine 1.3.0, which is bundled.

= 0.5.0 =
* Transfer archives no longer pile up. Every push and every pull used to leave a full copy of the site behind in the backup folder, where nothing ever removed it: on a site synced weekly that is a new multi-hundred-megabyte file every week. Snapshots and push exports now live in the job's working directory and are removed when the job ends.
* Safety copies taken before an import go to their own folder, so they are recognisable as such in the backup list and are kept to their own limit instead of crowding out real backups.
* Requires db-engine 1.2.0, which is bundled.

= 0.4.6 =
* base64 download fallback: some servers (mod_security/WAF) reject the binary ZIP response of a pull, resetting the connection before a single byte even for tiny ranges, because they flag the ZIP/SQL signature in the body. When the raw download is refused, the client now switches to a base64-over-JSON transport for the same offset. The JSON response is text and passes the filter, so pull works on those hosts too. Falls back to smaller blocks if needed; the progress window shows the switch. Both sites must run 0.4.6.

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
