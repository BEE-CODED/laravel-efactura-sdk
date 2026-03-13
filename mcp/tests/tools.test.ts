import { describe, it, expect } from "vitest";
import { configReferenceContent } from "../src/content/config-reference.js";
import { enumValuesContent } from "../src/content/enum-values.js";
import { dtoStructuresContent } from "../src/content/dto-structures.js";
import { sdkDocsContent } from "../src/content/sdk-docs.js";
import { apiReferenceContent } from "../src/content/api-reference.js";

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

const VALID_ENUMS = [
  "InvoiceTypeCode",
  "MessageFilter",
  "ExecutionStatus",
  "DocumentStandardType",
  "StandardType",
  "TaxCategoryId",
  "UploadStatusValue",
];

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

const VALID_DTOS = [
  "InvoiceData", "InvoiceLineData", "PartyData", "InvoiceAddressData",
  "UploadOptionsData", "OAuthTokensData", "AuthUrlSettingsData",
  "ListMessagesParamsData", "PaginatedMessagesParamsData",
  "UploadResponseData", "StatusResponseData", "DownloadResponseData",
  "ValidationResultData", "ListMessagesResponseData",
  "PaginatedMessagesResponseData", "MessageDetailsData",
  "CompanyData", "CompanyLookupResultData", "CompanyAddressData",
  "VatRegistrationData", "SplitVatData", "InactiveStatusData",
];

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

const VALID_TOPICS = [
  "overview", "invoice-flow", "credit-notes", "tax-calculation",
  "oauth-flow", "error-handling", "address-sanitization",
  "rate-limiting", "company-lookup",
];

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

const VALID_SERVICES = [
  "EFacturaClient", "AnafAuthenticator", "UblBuilder",
  "InvoiceBuilder", "AnafDetailsClient",
];

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
