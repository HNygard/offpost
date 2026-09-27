# Complaints (klage) in Offpost — roadmap

Status: direction, 2026-09-27. Not an implementation plan: each step gets its
own concrete plan when we get there.

## Goal

When an entity refuses a request, fully or partly, Offpost helps us complain.
A complaint is optional: we decide per thread whether to send one.

## Builds on

The cumulative thread state from
`docs/superpowers/plans/2026-09-27-innsynskrav-classification-roadmap.md`
(step 2). Each requested item has a status and, when refused, a
`denial_basis` with `refs`, `text` and `issues`. The issues
(`NO_REASON_GIVEN`, `NO_LEGAL_REFERENCE`, `INCOMPLETE_REFERENCE`) are
complaint grounds on their own: a refusal without a proper legal basis is not
a proper refusal. The thread statuses already cover the path:
`COMPLAINT_SENT`, `COMPLAINT_FORWARDED`, `COMPLAINT_DECIDED`,
`OMBUD_COMPLAINT_SENT`.

## Two ways to complain

- **Drafted by Offpost, approved by a human.** Offpost drafts a complaint
  from the thread state: the refused items, the entity's basis and its
  issues. A human reviews, edits and approves it in the GUI before it is
  sent. Nothing is sent without that approval.
- **Written by a human.** The human writes the complaint as a reply, as
  today. The thread state then records it like a drafted one.

## Steps

1. Show when a complaint is possible: a refused item, the complaint deadline,
   and the grounds from `denial_basis.issues`.
2. Human-written complaint as a reply, recognised as a complaint in the
   thread state.
3. Drafted complaint with human approval in the GUI.
4. Follow the complaint through the entity's reassessment, the appeal body
   and, when relevant, Sivilombudet.

The owner is an expert on offentleglova, complaint processes and
Sivilombudet: interview them on the details (deadlines, grounds, wording)
when a step starts.
