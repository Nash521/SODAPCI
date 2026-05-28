# Contact Page Institutional Design

Date: 2026-05-26

## Goal
Redesign `contact.html` so the contact page feels institutional, trustworthy, polished, and pleasant to use while keeping the existing static site architecture.

## Selected Direction
Direction B: corporate propre. The page should stay sober and highly readable, with premium hover/focus effects that improve clarity rather than feeling decorative.

## Scope
- Replace the template English contact copy with French SODAP-CI content.
- Improve the page header with a clean institutional visual treatment.
- Add contact method cards for address, email, phone, and hours.
- Modernize the form with clear labels, richer focus states, and hover feedback.
- Present the Google map in a polished framed section with a supporting information card.
- Keep the current static HTML, Tailwind utility classes, Bootstrap compatibility, Font Awesome/Bootstrap icons, and WOW animation library.

## UX Requirements
- The primary CTA must be obvious and inviting.
- Hover states should be visible on cards, buttons, and key links.
- Form focus states should be accessible and visibly green.
- Mobile layout must stack cleanly without horizontal overflow.
- The design must avoid adding new external JavaScript dependencies.

## Files
- Modify `contact.html` for page-specific markup and inline page-specific CSS.
- Add `tools/check-contact-page.mjs` to verify important page content and classes.
- Update `package.json` with a contact-page check script.

## Verification
Run the contact-page check and the existing project check. Run the Tailwind build if utility changes require regenerated CSS.
