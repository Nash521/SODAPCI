import { existsSync, readFileSync } from "node:fs";

const failures = [];
const scriptPath = "js/forms.js";

if (!existsSync(scriptPath)) {
  failures.push(`Missing form script: ${scriptPath}`);
} else {
  const script = readFileSync(scriptPath, "utf8");
  const requiredSnippets = [
    "[data-email-form]",
    "new FormData(form)",
    'Accept: "application/json"',
    "response.ok",
    "form.reset()",
    'status.setAttribute("role", "alert")',
  ];

  for (const snippet of requiredSnippets) {
    if (!script.includes(snippet)) {
      failures.push(`Missing required snippet: ${snippet}`);
    }
  }
}

if (failures.length > 0) {
  console.error("Form script verification failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log("Form script verification passed.");
