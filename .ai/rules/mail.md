---
paths:
  - 'app/Models/EmailTemplate.php,app/Models/PlatformEmailTemplate.php,app/Services/Mail/**'
---

# Mail

## Transactional/system templates are always on; marketing templates lock while a campaign runs
Decided 2026-09-25: transactional (tenant) and system (platform) email templates have no on/off switch. The app must always send them, so only subject/body are editable, and the model forces status=active and blocks delete (GuardsEmailTemplateUsage). Status (active/draft/archived) applies to marketing templates only. A marketing template used by a scheduled/queued/sending campaign is locked (no subject/body/status change, no delete), because a running campaign re-reads its template every batch. A template referenced by any campaign can never be deleted, only archived. CampaignSender::sendNow()/schedule() reject a campaign whose template isn't an active marketing template of the same owner. These rules live on the model; policies and form whitelists mirror them for the UI. Don't move them into the UI only.
