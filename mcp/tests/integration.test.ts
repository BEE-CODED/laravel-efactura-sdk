import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { spawn, type ChildProcess } from "child_process";
import { readFileSync } from "fs";
import { join } from "path";
import { createInterface } from "readline";

// These tests drive the built server (dist/index.js) over stdio, as a client does,
// so run `npm run build` first.
const packageRoot = join(import.meta.dirname, "..", "..");

type ToolResult = { isError?: boolean; content: { type: string; text: string }[] };

function startServer(proc: ChildProcess) {
  const rl = createInterface({ input: proc.stdout! });
  const pending = new Map<number, (message: { result?: unknown; error?: unknown }) => void>();
  let nextId = 1;

  rl.on("line", (line) => {
    try {
      const message = JSON.parse(line);
      pending.get(message.id)?.(message);
      pending.delete(message.id);
    } catch {
      /* not a JSON-RPC line */
    }
  });

  const request = (method: string, params: Record<string, unknown> = {}) =>
    new Promise<{ result?: unknown; error?: unknown }>((resolve) => {
      const id = nextId++;
      pending.set(id, resolve);
      proc.stdin!.write(JSON.stringify({ jsonrpc: "2.0", id, method, params }) + "\n");
    });

  return {
    request,
    notify: (method: string) => proc.stdin!.write(JSON.stringify({ jsonrpc: "2.0", method }) + "\n"),
    call: async (name: string, args: Record<string, unknown> = {}) =>
      (await request("tools/call", { name, arguments: args })).result as ToolResult,
  };
}

// H2/H3 heading lines outside code fences, read independently of the server.
function headingLines(file: string): string[] {
  let fenced = false;
  return readFileSync(join(packageRoot, file), "utf8")
    .split("\n")
    .filter((line) => {
      if (/^\s*(```|~~~)/.test(line)) fenced = !fenced;
      return !fenced && /^#{2,3} /.test(line);
    });
}

const topicsFrom = (result: ToolResult): string[] => result.content[0].text.split("Topics: ")[1].split(", ");

describe("MCP server", () => {
  let proc: ChildProcess;
  let client: ReturnType<typeof startServer>;

  beforeAll(async () => {
    proc = spawn("node", [join(import.meta.dirname, "..", "dist", "index.js")], { stdio: ["pipe", "pipe", "pipe"] });
    proc.stderr!.resume();
    client = startServer(proc);

    const init = (await client.request("initialize", {
      protocolVersion: "2025-03-26",
      capabilities: {},
      clientInfo: { name: "test", version: "1.0.0" },
    })) as { result: { serverInfo: { name: string } } };
    expect(init.result.serverInfo.name).toBe("efactura-sdk");
    client.notify("notifications/initialized");
  });

  afterAll(() => {
    proc.kill();
  });

  it("lists the three reader tools", async () => {
    const { result } = (await client.request("tools/list")) as { result: { tools: { name: string }[] } };

    expect(result.tools.map((t) => t.name).sort()).toEqual(["get-changelog", "get-config-reference", "get-sdk-docs"]);
  });

  it("serves every README H2/H3 section, and lists the topics for an unknown one", async () => {
    const unknown = await client.call("get-sdk-docs", { topic: "no-such-topic" });
    expect(unknown.isError).toBe(true);

    const topics = topicsFrom(unknown);
    const served: string[] = [];
    for (const topic of topics) {
      const result = await client.call("get-sdk-docs", { topic });
      expect(result.isError, topic).toBeUndefined();
      served.push(result.content[0].text.split("\n")[0]);
    }

    expect(new Set(topics).size).toBe(topics.length);
    expect(served).toEqual(headingLines("README.md"));
    expect(topics).toContain("configuration");
    expect(topics).toContain("generating-invoice-xml");
  });

  it("serves the whole CHANGELOG, and each of its sections", async () => {
    const whole = await client.call("get-changelog");
    expect(whole.content[0].text).toBe(readFileSync(join(packageRoot, "CHANGELOG.md"), "utf8"));

    const topics = topicsFrom(await client.call("get-changelog", { section: "no-such-section" }));
    const served: string[] = [];
    for (const section of topics) {
      served.push((await client.call("get-changelog", { section })).content[0].text.split("\n")[0]);
    }

    expect(served).toEqual(headingLines("CHANGELOG.md"));
  });

  it("serves every config file", async () => {
    const result = await client.call("get-config-reference");

    expect(result.isError).toBeUndefined();
    expect(result.content[0].text).toContain("## config/efactura-sdk.php");
    expect(result.content[0].text).toContain(readFileSync(join(packageRoot, "config", "efactura-sdk.php"), "utf8").trim());
  });
});
