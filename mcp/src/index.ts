import { readdirSync, readFileSync } from "node:fs";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// A thin reader: it serves the package's own README.md, CHANGELOG.md and config/*.php,
// read on every call, and holds no documentation of its own.
// The package root is two levels up from mcp/dist/index.js (and from mcp/src/index.ts).
const root = new URL("../../", import.meta.url);
const read = (path: string): string => readFileSync(new URL(path, root), "utf8");

type Section = { slug: string; text: string };

// GitHub's heading anchor: lower case, punctuation dropped, each space a hyphen.
const slugOf = (title: string): string =>
  title.trim().toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, "").replace(/\s/g, "-");

// The H2 and H3 sections of a markdown file. A section runs to the next heading of the same
// or a higher level, so an H2 includes its H3s. Lines inside code fences are not headings.
function sections(markdown: string): Section[] {
  const lines = markdown.split("\n");
  const headings: { line: number; level: number; slug: string }[] = [];
  const seen = new Map<string, number>();
  let fenced = false;

  lines.forEach((line, index) => {
    if (/^\s*(```|~~~)/.test(line)) {
      fenced = !fenced;
      return;
    }
    const match = fenced ? null : /^(#{1,6})\s+(.+?)\s*#*\s*$/.exec(line);
    if (!match) return;
    const base = slugOf(match[2]);
    const count = seen.get(base) ?? 0;
    seen.set(base, count + 1);
    headings.push({ line: index, level: match[1].length, slug: count ? `${base}-${count}` : base });
  });

  return headings
    .filter((h) => h.level === 2 || h.level === 3)
    .map((h) => {
      const end = headings.find((o) => o.line > h.line && o.level <= h.level)?.line ?? lines.length;
      return { slug: h.slug, text: lines.slice(h.line, end).join("\n").trim() };
    });
}

const answer = (text: string, isError = false) => ({
  content: [{ type: "text" as const, text }],
  ...(isError ? { isError: true } : {}),
});

function section(file: string, topic: string) {
  const all = sections(read(file));
  const hit = all.find((s) => s.slug === topic);
  return hit
    ? answer(hit.text)
    : answer(`Unknown topic "${topic}" in ${file}. Topics: ${all.map((s) => s.slug).join(", ")}`, true);
}

const server = new McpServer({ name: "efactura-sdk", version: "2.0.0" });

server.registerTool(
  "get-sdk-docs",
  {
    description:
      "Get one section of the Laravel e-Factura SDK README. The topic is the section's heading anchor " +
      '(an H2 or H3 slug, such as "configuration" or "generating-invoice-xml"); an unknown topic lists them all.',
    inputSchema: { topic: z.string().describe("README H2/H3 heading slug") },
  },
  async ({ topic }) => section("README.md", topic),
);

server.registerTool(
  "get-changelog",
  {
    description:
      "Get the Laravel e-Factura SDK CHANGELOG (the 3.x to 4.0 upgrade list), or one section of it by its " +
      "H2/H3 heading slug; an unknown section lists them all.",
    inputSchema: { section: z.string().optional().describe("CHANGELOG H2/H3 heading slug; omit for the whole file") },
  },
  async ({ section: topic }) => (topic === undefined ? answer(read("CHANGELOG.md")) : section("CHANGELOG.md", topic)),
);

server.registerTool(
  "get-config-reference",
  { description: "Get the Laravel e-Factura SDK configuration files (config/*.php), with their comments" },
  async () =>
    answer(
      readdirSync(new URL("config/", root))
        .filter((file) => file.endsWith(".php"))
        .sort()
        .map((file) => `## config/${file}\n\n\`\`\`php\n${read(`config/${file}`).trimEnd()}\n\`\`\``)
        .join("\n\n"),
    ),
);

async function main() {
  await server.connect(new StdioServerTransport());
  console.error("efactura-sdk MCP server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
