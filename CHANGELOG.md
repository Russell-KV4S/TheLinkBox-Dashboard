# Changelog

Versions follow `MAJOR.MINOR.PATCH`: MAJOR for a redesign or a change that needs manual steps on upgrade,
MINOR for new features, PATCH for fixes. The number lives in `TLB_VERSION` in `irlp/tlb-lib.php` and is
shown in the page footer. Bump it with every release, and add a section here.

## 2.3.0
- EchoLink stations linked to the AllStar node (shown by AllStar as 3 + the EchoLink node number) now show
  their callsign and description, looked up in EchoLink's public station list (fetched at most every 10 minutes).
  This works in the AllStar panel, the network diagram, browser alerts and the Last heard log.

## 2.2.0
- AllStar live feed also works with Allmon2 (address contains `allmon2`); the notifier reads its event stream.
- "Save and test" in the AllStar tab recognises Allmon2 addresses.
- Dashboard version shown in the page footer; this changelog.

## 2.1.0
- Last heard log with a 24-hour timeline and per-station talk time.
- Server health cards (CPU, memory, disk, network).
- Optional custom footer link; the Alerts tab became Settings.

## 2.0.0
- Rebuilt dashboard: live station list, AllStar panel and map, network diagram, browser alerts, password-protected admin area, seven alert channels.
