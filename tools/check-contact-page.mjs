import { readFileSync } from "node:fs";

const html = readFileSync("contact.html", "utf8");
const contactPageMatch = html.match(/<!-- Page Header Start -->[\s\S]*?<!-- Contact CTA End -->/);
const contactPageHtml = contactPageMatch ? contactPageMatch[0] : "";

const mustInclude = [
  "Contactez SODAP-CI",
  "Une question, un projet, un partenariat",
  "contact-hero",
  "contact-card",
  "contact-shell",
  "contact-form-panel",
  "contact-map-panel",
  "Yamoussoukro, quartier 50 logements",
  "carriere@lasodapci.com",
  "+225 27-33-75-73-12",
  "Demander un accompagnement",
  "Nos coordonnées",
  "Réponse sous 24h ouvrées",
  'href="#contact-form"',
  "contact-card:hover",
  ".contact-form-panel .contact-field:focus",
  ".contact-submit::after",
];

const mustNotInclude = [
  "Have Any Query",
  "Contact Us",
  "Your Name",
  "Your Email",
  "Submit Now",
  "info@example.com",
  "123 Street, New York, USA",
  "New%20York",
  "rounded-4",
  "rounded-pill",
  "text-white-50",
  "fw-bold",
  "display-4",
  " p-lg-5",
  " mb-lg-5",
  "text-lg-end",
  "align-items-stretch",
];

const failures = [];

function formById(id) {
  return html.match(new RegExp(`<form\\b(?=[^>]*\\bid="${id}")[^>]*>[\\s\\S]*?<\\/form>`, "i"))?.[0] ?? "";
}

function requireFormContract(id, formType, fields) {
  const form = formById(id);

  if (!form) {
    failures.push(`Missing form: #${id}`);
    return;
  }

  const requiredSnippets = [
    'action="api/send-form.php"',
    'method="post"',
    'enctype="multipart/form-data"',
    `name="form_type" value="${formType}"`,
    'name="website"',
    'data-email-form',
    'data-form-status',
    ...fields.map((field) => `name="${field}"`),
  ];

  for (const snippet of requiredSnippets) {
    if (!form.includes(snippet)) {
      failures.push(`Missing required snippet in #${id}: ${snippet}`);
    }
  }
}

if (!contactPageHtml) {
  failures.push("Unable to locate contact page content between expected comments.");
}

for (const snippet of mustInclude) {
  if (!html.includes(snippet)) {
    failures.push(`Missing expected snippet: ${snippet}`);
  }
}

for (const snippet of mustNotInclude) {
  if (html.includes(snippet)) {
    failures.push(`Unexpected template snippet remains: ${snippet}`);
  }
}

const classTokens = [
  ...contactPageHtml.matchAll(/class="([^"]+)"/g),
].flatMap((match) => match[1].split(/\s+/).filter(Boolean));

const legacyContactTokens = new Set([
  "container",
  "container-fluid",
  "row",
  "form-floating",
  "form-control",
  "form-select",
  "breadcrumb",
  "breadcrumb-item",
  "d-flex",
  "me-3",
  "mt-lg-0",
  "rounded-circle",
  "position-relative",
  "position-absolute",
  "w-100",
  "h-100",
]);

for (const token of classTokens) {
  if (legacyContactTokens.has(token) || /^col-(?:\d+|sm|md|lg|xl)/.test(token)) {
    failures.push(`Legacy Bootstrap class remains in contact page content: ${token}`);
  }
}

requireFormContract("contact-form", "contact", ["name", "email", "phone", "subject", "message"]);
requireFormContract("application-form", "application", ["name", "email", "position", "cv", "cover_letter"]);

const applicationForm = formById("application-form");
for (const snippet of [
  'accept=".pdf,.doc,.docx"',
  '5 Mo maximum par fichier',
  '10 Mo maximum au total',
]) {
  if (!applicationForm.includes(snippet)) {
    failures.push(`Missing upload guidance in #application-form: ${snippet}`);
  }
}

if (failures.length > 0) {
  console.error("Contact page verification failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log("Contact page verification passed.");
