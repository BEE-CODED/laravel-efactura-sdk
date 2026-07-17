import { describe, it, expect } from "vitest";
import { configReferenceContent } from "../src/content/config-reference.js";
import { enumValuesContent } from "../src/content/enum-values.js";
import { dtoStructuresContent } from "../src/content/dto-structures.js";
import { sdkDocsContent } from "../src/content/sdk-docs.js";
import { apiReferenceContent } from "../src/content/api-reference.js";
import { VALID_ENUMS, VALID_DTOS, VALID_TOPICS, VALID_SERVICES } from "../src/registry.js";

// Registry/content parity. These four assertions are the reason a topic can no
// longer ship untested: previously each list below was re-declared in this file,
// so `migration-v2-v3` was added to the server and silently skipped here.
//
// Checking both directions matters:
//   registry \ content = server advertises a topic that returns an error
//   content \ registry = documentation written but unreachable through the tool
describe("registry/content parity", () => {
  const cases: [string, readonly string[], Record<string, string>][] = [
    ["VALID_ENUMS / enumValuesContent", VALID_ENUMS, enumValuesContent],
    ["VALID_DTOS / dtoStructuresContent", VALID_DTOS, dtoStructuresContent],
    ["VALID_TOPICS / sdkDocsContent", VALID_TOPICS, sdkDocsContent],
    ["VALID_SERVICES / apiReferenceContent", VALID_SERVICES, apiReferenceContent],
  ];

  it.each(cases)("%s expose exactly the same keys", (_label, registry, content) => {
    expect([...registry].sort()).toEqual(Object.keys(content).sort());
  });
});

describe("get-config-reference", () => {
  it("returns non-empty markdown content", () => {
    expect(configReferenceContent).toBeTruthy();
    expect(typeof configReferenceContent).toBe("string");
    expect(configReferenceContent.length).toBeGreaterThan(100);
  });

  it("contains key config sections", () => {
    expect(configReferenceContent).toContain("sandbox");
    expect(configReferenceContent).toContain("oauth");
    expect(configReferenceContent).toContain("EFACTURA_CLIENT_ID");
    expect(configReferenceContent).toContain("rate_limits");
    expect(configReferenceContent).toContain("endpoints");
  });
});

describe("get-enum-values", () => {
  it.each(VALID_ENUMS)("returns content for %s", (name) => {
    expect(enumValuesContent[name]).toBeTruthy();
    expect(typeof enumValuesContent[name]).toBe("string");
    expect(enumValuesContent[name].length).toBeGreaterThan(20);
  });

  it("returns undefined for unknown enum", () => {
    expect(enumValuesContent["FakeEnum"]).toBeUndefined();
  });
});

describe("get-dto-structure", () => {
  it.each(VALID_DTOS)("returns content for %s", (name) => {
    expect(dtoStructuresContent[name]).toBeTruthy();
    expect(typeof dtoStructuresContent[name]).toBe("string");
  });

  it("InvoiceLineData mentions taxAmount as required", () => {
    expect(dtoStructuresContent["InvoiceLineData"]).toContain("taxAmount");
    expect(dtoStructuresContent["InvoiceLineData"]).toContain("required");
  });

  it("returns undefined for unknown DTO", () => {
    expect(dtoStructuresContent["FakeDto"]).toBeUndefined();
  });
});

describe("get-sdk-docs", () => {
  it.each(VALID_TOPICS)("returns content for topic '%s'", (topic) => {
    expect(sdkDocsContent[topic]).toBeTruthy();
    expect(typeof sdkDocsContent[topic]).toBe("string");
    expect(sdkDocsContent[topic].length).toBeGreaterThan(50);
  });

  it("credit-notes topic covers sign conventions", () => {
    expect(sdkDocsContent["credit-notes"]).toContain("negative");
    expect(sdkDocsContent["credit-notes"]).toContain("precedingInvoiceNumber");
  });

  it("tax-calculation topic covers taxAmount requirement", () => {
    expect(sdkDocsContent["tax-calculation"]).toContain("taxAmount");
    expect(sdkDocsContent["tax-calculation"]).toContain("required");
  });

  it("error-handling covers all exception classes", () => {
    const content = sdkDocsContent["error-handling"];
    expect(content).toContain("EFacturaException");
    expect(content).toContain("AuthenticationException");
    expect(content).toContain("ValidationException");
    expect(content).toContain("ApiException");
    expect(content).toContain("RateLimitExceededException");
    expect(content).toContain("XmlParsingException");
  });

  it("returns undefined for unknown topic", () => {
    expect(sdkDocsContent["fake-topic"]).toBeUndefined();
  });
});

describe("get-api-reference", () => {
  it.each(VALID_SERVICES)("returns content for %s", (service) => {
    expect(apiReferenceContent[service]).toBeTruthy();
    expect(typeof apiReferenceContent[service]).toBe("string");
  });

  it("EFacturaClient documents token refresh behavior", () => {
    expect(apiReferenceContent["EFacturaClient"]).toContain("wasTokenRefreshed");
  });

  it("EFacturaClient documents factory method", () => {
    expect(apiReferenceContent["EFacturaClient"]).toContain("fromTokens");
  });

  it("returns undefined for unknown service", () => {
    expect(apiReferenceContent["FakeService"]).toBeUndefined();
  });
});
