# Security model

`wol-webui` is an **unauthenticated LAN-only administrative tool**. Every user who can reach its web port can register, delete, discover, or wake computers and clear history. **Never expose it directly to the Internet**. Enforce network access with a firewall, VLAN access controls, and/or Tailscale ACLs. Use HTTPS/Tailscale Serve if passive LAN sniffing or session hijacking is in scope.

Controls: CSRF-token-protected POSTs, SameSite=Strict and HttpOnly session cookies, no state changes via GET, output escaping, PHP data validation, configured IPv4 subnet allowlist, no shell invocation, HTML security headers and restrictive CSP, SQLite prepared statements, atomic send throttle (5s per machine, 20/min globally), config and DB outside the document root, no secrets or live targets committed to Git.

Known limits: this is **not** an identity-aware system; CSRF mitigation is not access control. Anyone on an allowed network may use the app. The MAC-to-IP feature consults local kernel ARP state only and cannot discover arbitrary remote/offline IP addresses. Rate limiting is a basic abuse guard, not DDoS protection. Broadcast cannot normally traverse VLANs or Tailscale; attach the sender to each intended L2 network. WOL does not prove the host turned on; local UDP send success is not remote delivery proof. Do not publish live MAC addresses, database backups, or private config. Keep OS/PHP/Apache patched.

If reporting a vulnerability, avoid publishing sensitive network details in the issue tracker. For deployments needing per-user authorization, use an authenticated reverse proxy or a different product with native access management; this application intentionally has no authentication.
