# Test Characters

Live retail characters used as integration-test and FE smoke-test fixtures.
All entries come from real Blizzard accounts and are exercised against the live
Blizzard API by `tests/Feature/Endpoints/RetailCharacterEndpointTest`.

## Per-class roster (EU MN Season 1)

One top-ranked character per WoW retail class, sampled from raider.io's EU
Mythic+ Season-MN-1 leaderboard runs
(`/api/v1/mythic-plus/runs?season=season-mn-1&region=eu`). ASCII-only names so
they paste cleanly into URLs and don't surface URL-encoding bugs unrelated to
what the test is exercising.

Captured 2026-05-01. Constant: `EndpointIntegrationTestCase::RETAIL_CHARACTERS_BY_CLASS`.

| Class        | Name        | Realm         | Region |
|--------------|-------------|---------------|--------|
| Death Knight | Shuhdkk     | draenor       | EU     |
| Demon Hunter | Speculation | tarren-mill   | EU     |
| Druid        | Turbogronil | archimonde    | EU     |
| Evoker       | Nqhover     | kazzak        | EU     |
| Hunter       | Dpxhunt     | blackhand     | EU     |
| Mage         | Khaelt      | sylvanas      | EU     |
| Monk         | Maitaimonk  | kazzak        | EU     |
| Paladin      | Poznasme    | draenor       | EU     |
| Priest       | Boreasxo    | kazzak        | EU     |
| Rogue        | Tomelvis    | draenor       | EU     |
| Shaman       | Fauni       | stormreaver   | EU     |
| Warlock      | Dendeeb     | kazzak        | EU     |
| Warrior      | Farover     | ravencrest    | EU     |

URL form: `/api/v1/characters/{region}/{realm}/{name}` — e.g.
`/api/v1/characters/eu/draenor/Shuhdkk`. Names are case-preserved by the test;
`BlizzardIdentity::realm()` slugifies the realm and `BlizzardIdentity::name()`
normalizes the name, so the URL works whether you use `Shuhdkk`, `shuhdkk`, or
`SHUHDKK`.

### Refresh policy

These rosters drift each season as players reroll, transfer, or quit. Refresh
when:

- Integration tests start failing on the leaderboard fixture for a 404 / "not
  found" reason (character was renamed, transferred, or deleted).
- A new Mythic+ season starts and the meta shifts away from the captured
  classes (most current characters will still exist, but their data freshness
  may degrade if they stop playing the season).

To regenerate, run the same raider.io API query from the playwright session
log on 2026-05-01, bucket roster members by class, and prefer the
highest-ranked ASCII-name pick per class.

## Slot-based fixtures (legacy)

`EndpointIntegrationTestCase::RETAIL_CHARACTERS` still keys characters by the
data shape they exercise (`geared_main`, `pvp_player`, `profession_rich`,
`raider`, `rep_grinder`). These predate the per-class roster and remain the
canonical fixture for the existing data-provider tests in
`RetailCharacterEndpointTest`.

`Melaniya` (eu / the-maelstrom) is also the FE smoke-test character — see
`reference_test_characters` in auto-memory.
