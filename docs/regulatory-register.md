# SahakariOS — Regulatory Register

Research date: 2026-10-16 AD (2083 Asoj 30 BS). Researcher: AI-assisted reading of official
documents. **This register is not legal advice.** Every rule must be confirmed by a qualified
cooperative accountant or legal professional before the software enforces it in production.

## How to read this register

| Status | Meaning |
|---|---|
| **VERIFIED** | Read in the official text listed in the Source column. Section/point numbers given. Reading of scanned Nepali pages may still contain errors; confirm before go-live. |
| **UNVERIFIED** | Mentioned in secondary sources, or the official text was not obtained or not read. Do not enforce in software. |
| **CONFLICT** | Two official texts say different things. Needs professional decision. |
| **CHECK SCAN** | Read from a scanned page where the digits or wording looked unusual. |

Rule for the software: regulatory numbers are stored as **effective-dated configuration with a
reference to the row ID below**, never hard-coded. Changing a rule = new configuration row + test update.

Abbreviations: **Act** = Cooperatives Act 2074 (consolidated). **Std** = NCRA Regulatory Standard
for SACCO operations 2082, as amended (cited as chapter/point). **NCRA** = National Cooperative
Regulatory Authority (राष्ट्रिय सहकारी नियमन प्राधिकरण).

## 1. Source documents

| ID | Instrument | Date (BS) | Status of reading | Source |
|---|---|---|---|---|
| L1 | Cooperatives Act 2074, Law Commission consolidated text including amendments of 2075-11-19, 2080-12-30, 2081-12-18 (created NCRA, ch. 17क) and Cooperative (First Amendment) Act 2083 dated 2083-03-25 | Notice 2083-06-01 | Full text read | https://ncra.gov.np/content/13389/ |
| S1 | Regulatory Standard for SACCO operations 2082 (base), approved 2082-02-09 | 2082-02-09 | All 32 scanned pages read | https://ncra.gov.np/content/13312/ |
| S1a | S1 — 1st amendment (foreclosed asset provisioning) | 2082-08-09 | Read | https://ncra.gov.np/content/13339/ |
| S1b | S1 — 2nd amendment (AML instructions) | — | **Not read** | https://ncra.gov.np/content/13379/ |
| S1c | S1 — 3rd amendment 2083 | 2083-05-10 | Read | https://ncra.gov.np/content/13391/ |
| S2 | SACCO registration/enlistment standard 2082 — 7th amendment 2083 | 2083-05-10 | Read | https://ncra.gov.np/content/13390/ |
| D1 | NCRA directive 1/2081/82 (rescheduling, interest recognition) | 2082-03-22/23 | Read | https://ncra.gov.np/content/13319/ |
| D2 | NCRA directive to SACCOs 2083/03/32 | 2083-03-32 | Read | https://ncra.gov.np/content/13380/ |
| D3 | Credit Information Centre membership notice | 2082-08-10 | Read | https://ncra.gov.np/content/13338/ |
| A1 | AML/CFT Guidance to Cooperatives (amended 11 Sep 2026) | 2083-05-26 | All 14 pages read | https://ncra.gov.np/content/13388/ |
| A2 | AML/CFT **Instructions 2082** for SACCOs (incl. KYM forms Schedule-1/2/3) | Kartik 2082 | All 23 pages read | https://ncra.gov.np/content/13334/ |
| A3 | Targeted Financial Sanctions Guideline for Cooperatives 2025 (notice 2082/07/30) | 2082-07-30 | Read | https://ncra.gov.np/content/13333/ |
| A4 | Directive under regulatory standard (2nd amendment): fit-and-proper/beneficial-owner details | 2083-03-08 | Read | https://ncra.gov.np/content/13379/ |
| A5 | AML inspection & supervision procedure 2082 | 2082 | **Not read** | https://ncra.gov.np/content/13335/ |
| C1 | NCRA collection of guidelines, procedures, standards | 2083-04-06 | **Not read** | https://ncra.gov.np/content/13383/ |
| X1 | Asset (Money) Laundering Prevention Act 2064, Rules 2081; FIU-Nepal TTR guideline (July 2025) | — | **Not read** (scanned) | https://www.nrb.org.np/fiu/threshold-transaction-reporting-guidelines-updated-july-2025 |
| X2 | Individual Privacy Act 2075 | 2075 | **Not read** | https://lawcommission.gov.np/content/12261/12261-the-privacy-act-2075/ |
| X3 | Cooperative Rules (नियमावली) 2075, current text | — | **Not obtained** (Law Commission repository refused connections) | https://repository.lawcommission.gov.np/ |

The Act's 1st amendment (2083-03-25) is later than S1 (2082-02-09). Where they differ, the row is
marked CONFLICT; this register does not decide which applies.

## 2. Membership and shares

| ID | Rule | Source | Status |
|---|---|---|---|
| M-01 | Member: Nepali citizen aged 16+, resident in working area, holds ≥ 1 share | Act s.30 | VERIFIED |
| M-02 | Committee decides application within 35 days; rejection notified within 7 days with reasons | Act s.31 | VERIFIED |
| M-03 | One person may not be a member of more than one cooperative of the same type | Act s.32(1) | VERIFIED |
| M-04 | Membership cannot end while a loan, liability or guarantee is outstanding | Act s.34(2); Std 8/1 | VERIFIED |
| M-05 | Share face value Rs 100 | Act s.63(4) | VERIFIED |
| M-06 | One member may hold at most 20% of total share capital | Act s.63(2) | VERIFIED |
| M-07 | Shares sold only to members; not on open market | Act s.63(1),(6) | VERIFIED |
| M-08 | Shares may not secure a loan | Std 3/2.5 | VERIFIED |
| M-09 | Exit refund: Act says within 3 months of liabilities being settled; Std says share capital, unpaid dividend and protected capital within 30 days of approval of that year's audit report, reduced pro rata for accumulated losses | Act s.64(1); Std 8/2 | **CONFLICT** |
| M-10 | One member one vote | Act s.136 | VERIFIED |
| M-11 | Family definition (for collateral and governance rules) | Act s.2(घ); Std 1/2.7 | VERIFIED (definitions differ in detail — **CONFLICT** on scope) |

## 3. Savings

| ID | Rule | Source | Status |
|---|---|---|---|
| S-01 | Savings accepted only from members | Act s.50(1); Std 2/1.1 | VERIFIED |
| S-02 | Total savings ≤ 15 × primary capital fund | Act s.50(7); Std 2/1.2 | VERIFIED |
| S-03 | One member's savings ≤ 10% of primary capital fund; primary capital recomputed on Shrawan 1 and Magh 1 | Act s.52(1); Std 2/2.1 | VERIFIED |
| S-04 | Primary capital fund definition: Act = share capital + reserve fund; Std ch.6 core capital adds loss-compensation fund and accumulated profit/loss | Act s.2(ङ); Std 6/1 | **CONFLICT** |
| S-05 | Allowed accounts: general, regular, term up to 3 years | Std 2/3.1 | VERIFIED |
| S-06 | Regular savings ≥ 25% of total savings | Std 2/3.1 | VERIFIED |
| S-07 | Savings operating procedure approved by general assembly | Std 2/3.2 | VERIFIED |
| S-08 | Deposit > Rs 10 lakh: source of funds must be declared; refuse if not | Act s.52क; Std 2/4 | VERIFIED |
| S-09 | Withdrawal paid net of member's liabilities | Act s.64(2); Std 8/3 | VERIFIED |
| S-10 | Savings may be used only for member loans, government bonds, cooperative bank / Sana Kisan shares | Act s.50(6) | VERIFIED |
| S-11 | Savings interest calculation method | Std 5/2.1 (board decides) | Not prescribed — board decision |
| S-12 | Tax deducted at source on savings interest | Income Tax Act — not researched | **UNVERIFIED** |

## 4. Loans

| ID | Rule | Source | Status |
|---|---|---|---|
| L-01 | Loans only to members | Act s.50(1) | VERIFIED |
| L-02 | No loan within 3 months of membership; no unsecured loan within 6 months | Act s.50(8); Std 3/1.1 | VERIFIED |
| L-03 | One member's loan ≤ 15% of primary capital fund | Act s.52(2); Std 3/1.2 | VERIFIED |
| L-04 | Unsecured loan: regular savers only, ≤ lower of 5 × savings or Rs 3 lakh, ≥ 2 member guarantors | Std 3/1.3 | VERIFIED (news reports of Rs 5 lakh not matched in official text) |
| L-05 | Loan against own savings ≤ 90% of savings | Std 3/2.1 | VERIFIED |
| L-06 | Real-estate LTV: 60% metro/sub-metro; 70% municipality/rural municipality | Std 3/2.2 | **CHECK SCAN** |
| L-07 | Project-secured loans ≤ 80% of project cost, released in instalments | Std 3/2.4, 2.6 | VERIFIED |
| L-08 | Collateral only borrower's own or one-household family property; third-party collateral loans regularised by Asar end 2083 | Std 3/2.3 | VERIFIED |
| L-09 | Overdraft loans and gold/silver-collateral loans prohibited | Std 9/8 | VERIFIED |
| L-10 | Directors: no new loans except savings-secured / regular-saver type; may not guarantee loans | Std 3/1.4, 7/1.6 | VERIFIED |
| L-11 | CEO/manager and family: no loans | Std 7/4.1 | VERIFIED |
| L-12 | Staff: loans only under approved staff policy | Std 7/5.4 | VERIFIED |
| L-13 | Loans disbursed only through member's own account in the cooperative | Std 8/10 | VERIFIED |
| L-14 | Grace period by board for agri/industry/business; principal interval after grace ≤ 3 months | Std 3/1.8 | VERIFIED |
| L-15 | Large cooperative (savings or loans > Rs 25 crore): ≥ 50% of loans to productive sectors | Std 1/2.3, 3/1.7 | VERIFIED |
| L-16 | Collateral release letter within 7 working days of full recovery | Std 3/6 | VERIFIED |
| L-17 | CIC credit report needed above a threshold; collateral valuation by listed valuer above a threshold | Std 9/4, 9/9 | **UNVERIFIED** (thresholds "as prescribed", not found) |
| L-18 | Loans outside working area / to non-members prohibited | Act s.21, s.125(1) | VERIFIED |

## 5. Interest and charges

| ID | Rule | Source | Status |
|---|---|---|---|
| I-01 | Rates set from reference rate (Act s.51); same rate for same product for all members | Std 5/1.1–1.2 | VERIFIED (reference rate value **UNVERIFIED**) |
| I-02 | Spread ≤ 6% (weighted-average loan rate − weighted-average savings rate) | Act s.50(4); Std 5/1.3 | VERIFIED |
| I-03 | No capitalisation of loan interest into principal | Act s.50(5) | VERIFIED |
| I-04 | Penalty interest ≤ 5% of overdue interest | Act s.50(5क) | VERIFIED |
| I-05 | Loan interest calculated daily on declining balance | Std 5/2.3 | VERIFIED |
| I-06 | At disbursement only admin service fee, interest, penalty interest; third-party costs (e.g. CIC) at cost | Std 5/2.2 | VERIFIED |
| I-07 | Board decides rates, calculation method, penalty method, fees; rates may be reviewed monthly | Std 5/1.4, 5/2.1 | VERIFIED |
| I-08 | Quarterly report of rates, methods and fees within 15 days of quarter end | Std 5/3 | VERIFIED |
| I-09 | Day-count basis (365/366/actual) | — | **UNVERIFIED** — not found |

## 6. Classification and provisioning

| ID | Rule | Source | Status |
|---|---|---|---|
| P-01 | Pass: not overdue or overdue ≤ 3 months; savings-secured loans always Pass | Std 3/3.1 | VERIFIED (boundary at exactly 3 months not stated) |
| P-02 | Substandard 3–6 months; Doubtful 6–12 months; Loss > 12 months | Std 3/3.2 | VERIFIED |
| P-03 | Whole loan classified by the overdue period of any overdue instalment | Std 3/4.2 | VERIFIED |
| P-04 | Minimum provision on outstanding: Pass 1%, Substandard 25%, Doubtful 50%, Loss 100% | Std 3/4.1 | VERIFIED |
| P-05 | Always Loss: legacy third-party-collateral loans, out-of-area projects, personal loans without collateral or guarantee | Std 3/4.4 | VERIFIED |
| P-06 | Foreclosed asset at lower of market value and dues; 100% provision; relief 50% until Asar end 2083 for assets taken before Asar end 2082; no dividend/bonus until 100% | Std 3/7; S1a | VERIFIED |
| P-07 | Rescheduling only under Authority standard for special situation | Std 3/5 | VERIFIED |
| P-08 | Rescheduling conditions: apply within 3 months, 10% of accrued interest paid, ≤ 1 year, may upgrade one class, written-back provision held separately until 6 consecutive regular instalments, no fee | D1 §3–4 | VERIFIED |
| P-09 | Quarterly reports Tables 3.1 (foreclosed assets) and 3.2 (classification and provision) within 15 days | Std 3/8 | VERIFIED |
| P-10 | Write-off rules | — | **UNVERIFIED** — not found |

## 7. Liquidity and capital

| ID | Rule | Source | Status |
|---|---|---|---|
| C-01 | Liquid assets ≥ 15% of savings; FD counted at 90%, capped at 5% of savings; loans against FD/bonds deducted | Std 4/1–2 | VERIFIED |
| C-02 | Liquidity computed monthly from weekly averages; Table 4.1 within 7 days of month end | Std 4/3–4 | VERIFIED |
| C-03 | Core capital ≥ 4% of risk-weighted assets | Std 6/1 | VERIFIED |
| C-04 | Large cooperatives: total capital ≥ 8% of RWA; supplementary capital ≤ 100% of core | Std 6/2 | VERIFIED |
| C-05 | Risk weights: cash, government bonds, own-deposit-secured loans 0%; BFI / cooperative bank balances 20%; other loans and assets 100%; third-party-collateral loans 150% | Std 6/3 | VERIFIED (wording of 0% item differs between text and Table 6.2 — **CHECK SCAN**) |
| C-06 | Revaluation reserve in supplementary capital: lesser of reserve or 2% of total supplementary capital | Std 6/2 | **CHECK SCAN** |
| C-07 | Tables 6.1/6.2 within 1 month of quarter end | Std 6/4.3 | VERIFIED (table headers say Asar/Poush — **CHECK SCAN**) |
| C-08 | Borrowing from BFIs ≤ 5% of total assets and ≤ 100% of capital fund (collective-guarantee exception) | Std 2/1.3 | VERIFIED |

## 8. Funds, profit and tax

| ID | Rule | Source | Status |
|---|---|---|---|
| F-01 | Reserve fund ≥ 25% of net surplus, plus capital grants and fixed-asset sale proceeds; indivisible | Act s.68; Std 6/4.5 | VERIFIED |
| F-02 | Protected capital return fund ≥ 25% of surplus after reserve; distributed by member transaction share | Act s.69 | VERIFIED (distribution method in Rules — **UNVERIFIED**) |
| F-03 | Cooperative promotion fund 0.5% of surplus after reserve | Act s.70 | VERIFIED |
| F-04 | Share dividend ≤ 15% of share capital per year; none until minimum capital met | Act s.71; Std 6/4.4 | VERIFIED |
| F-05 | Income tax on financial cooperatives: 10% metro, 7% sub-metro, 5% municipality, 0% rural municipality | Act s.141 | VERIFIED (confirm against current Income Tax Act / Finance Act 2083) |

## 9. Accounting, audit, reporting, records

| ID | Rule | Source | Status |
|---|---|---|---|
| R-01 | Double-entry books per accounting standards of the competent body | Act s.74 | VERIFIED; which standard (e.g. NFRS for SMEs) **UNVERIFIED** |
| R-02 | Tampering with accounts or keeping parallel books is an offence (up to 10 years) | Act s.122(ढ), s.124 | VERIFIED |
| R-03 | External audit within 3 months of FY end; same auditor max 3 consecutive terms | Act s.75–76; Std 7/7 | VERIFIED |
| R-04 | Audit supervision committee internal audit every quarter | Act s.48–49; Std 7/3.2 | VERIFIED |
| R-05 | Annual reports to registrar and into Department Integrated MIS | Act s.73(1), (1क); Std 9/6 | VERIFIED (format **UNVERIFIED**) |
| R-06 | SACCOs adopt NCRA-prescribed monitoring system | Act s.97 | VERIFIED (system and format **UNVERIFIED**) |
| R-07 | CIC membership mandatory; loans > Rs 10 lakh reported; defaulters reported every 3 months | Act s.81; D3 | VERIFIED |
| R-08 | Deposit and Credit Guarantee Fund membership mandatory | Act s.101 | VERIFIED |
| R-09 | Balance sheet / P&L published in regulator's format | Std 7/2.4 | VERIFIED (format **UNVERIFIED**) |
| R-10 | Records-retention period | Act s.72(2) refers to Rules | **UNVERIFIED** |
| R-11 | Interest accrued for FY 2081/82 and FY 2082/83 counted as that year's income if received by the following Shrawan 15 | D1 §5; D2 | VERIFIED (general recognition basis **UNVERIFIED**) |

## 10. Licensing, governance, AML

| ID | Rule | Source | Status |
|---|---|---|---|
| G-01 | NCRA operating licence required, renewed annually; while suspended, no transactions | Act s.16क | VERIFIED |
| G-02 | Fit-and-proper documents uploaded to NCRA's electronic system | S2 3क | VERIFIED |
| G-03 | Board 5–9 members, ≥ 33% women where available; office-bearer term limit 2 terms; 4-year term | Std 7/1; Act s.41 | VERIFIED |
| G-04 | "Main business" threshold: Act says more than 50%; Std says at least 30% | Act s.2(ज); Std 1/2.12 | **CONFLICT** |
| G-05 | Comply with Money Laundering Prevention Act 2064 and AML directives; AML compliance reports to NCRA | Std 9/3; Act s.150क | VERIFIED (detailed KYC/TTR/STR duties **UNVERIFIED** until A1/A2/X1 are read) |
| G-06 | Privacy obligations for member personal data | X2 | **UNVERIFIED** |
| G-07 | Whether NRB directives currently add SACCO rules | Act s.23क, s.151(1क) | **UNVERIFIED** |

## 10a. Calendar and fiscal year (checked 2026-10-16)

| ID | Rule / data | Source | Status |
|---|---|---|---|
| CAL-01 | BS month lengths BS 2000–2083 in `backend/resources/calendar/bikram-sambat.php` | Three independent open-source datasets agree on every year (npm `bikram-sambat` 1.8.1, `nepali-datetime` 2.0.0, `nepali-date-converter` 3.4.0) | VERIFIED by consensus (not against the official panchanga for years before 2083) |
| CAL-02 | BS 2083: 31 BS/AD pairs match | MoHA public-holiday notice 2083, published 2082-11-18, via Pradhan & Associates alert (https://www.pradhanlaw.com/file-upload/files/ListofPubl-1773204479.pdf); Chaitra 30 = 2027-04-13 confirmed by hamropatro.com and bhuone.com.np | VERIFIED |
| CAL-03 | The alert's phrase "2083/12/31 (April 13, 2027)" | same alert | **CONFLICT** in wording only: every other source gives Chaitra 2083 30 days ending 2027-04-13; treated as loose wording of "year end" |
| CAL-04 | BS 2084 onward | datasets disagree (2084 Jestha/Asar 31/32 vs 32/31) | **UNVERIFIED — not loaded.** Conversions past 2027-04-13 fail on purpose |
| CAL-05 | Fiscal year = Shrawan 1 to last day of Asar | Standard Nepal government fiscal year; consistent with NCRA D1/D2 (Shrawan 15 rule) and Std 2/2.1 (Shrawan 1 / Magh 1 counts) | Practice VERIFIED; statute citation **UNVERIFIED** (Act text garbled in extraction) |
| CAL-06 | FY 2083/84 end date | needs Asar 2084 | **BLOCKED** until the official 2084 calendar (Nepal Panchanga Nirnayak Bikas Samiti) is loaded; stored as NULL |

Business-day rules (one open day per cooperative, forward-only, not in the future in Nepal time,
inside an open fiscal year) are **PROPOSED system controls**, not regulatory requirements.

## 10b. AML/CFT and KYC (read 2026-10-16)

| ID | Rule | Source | Status / where in software |
|---|---|---|---|
| K-01 | Identify and verify every member; applicant appears in person; no membership or transaction without required documents | A2 §1(1) | VERIFIED — Phase 4 (in-person timestamp, approval blocked without documents) |
| K-02 | KYM form for natural persons = Schedule-1 (sections क–ञ) | A2 §1(2), Sch-1 | VERIFIED — `App\Members\KymForm` |
| K-03 | Legal-person members: Schedule-2 | A2 §1(2)(ख) | VERIFIED — **not built yet** |
| K-04 | Thumbprint of individual (right and left on form); guardian's thumbprint for child savings | A2 §1(4)-(5), Sch-1 ज | VERIFIED — required documents |
| K-05 | Accepted ID copies: national ID, citizenship, voter ID, passport, driving licence, PAN; recent photo | A2 Sch-1 झ; A1 §IV | VERIFIED — approval requires photo + one ID copy |
| K-06 | Open accounts on the basis of the National ID number using the national ID electronic records | A2 §1(1)(घ) | VERIFIED — integration **not possible yet** (no access) |
| K-07 | PEP: identify at onboarding and on change; family/associates; **high risk**; higher-management approval | A2 §3(1)-(3); A1 §IV | VERIFIED — PEP ⇒ high; approval needs `member.decide_high_risk` |
| K-08 | PEP records kept **10 years** after leaving office | A2 §3(5) | VERIFIED — retention not yet automated |
| K-09 | Person remains PEP **5 years** after retirement; internal PEP list at least yearly | A1 §IV | VERIFIED (different rule from K-08) — not built |
| K-10 | Re-verify existing members: high **1 year**, medium **2 years**, low **3 years**; earlier on material change | A2 §4 | VERIFIED — `next_kyc_review_on`, review-due list |
| K-11 | EMDD (high risk): electronic-channel users, crime-risk, **> Rs 30 lakh/year** savings, shares or loans, unusual transactions, PEPs; also sensitive businesses (money transfer, real estate, precious metals, casino, manpower) | A2 §6(2), §21(5) | VERIFIED — manual risk assessment now; automatic triggers with transactions |
| K-12 | Source-of-funds disclosure for transactions **≥ Rs 10 lakh** (shares, savings deposits, loan repayments, remittance) | A2 §8; Act s.52क (> Rs 10 lakh) | VERIFIED — **CONFLICT**: "10 lakh or more" (A2) vs "more than 10 lakh" (Act); build for ≥ |
| K-13 | TTR to FIU within **15 days**: cash deposits **≥ Rs 10 lakh per member per day** (aggregated) | A2 §12; A1 §VI | VERIFIED — **CONFLICT**: A2 also includes cash withdrawals ≥ 10 lakh/day, A1 deposits only; build both |
| K-14 | TTR exemptions: government bodies, staff loans, cash with banks/FIs/cooperative bank | A2 §12(2) | VERIFIED — with savings module |
| K-15 | STR/SAR immediately via goAML, incl. attempted transactions; no tipping-off | A2 §13, §18; A1 §VI | VERIFIED — not built |
| K-16 | Sanctions screening before any transaction and continuously; freeze; notify NCRA within 3 working days (copy FIU) | A2 §2; A3 | VERIFIED — **CONFLICT** on freeze timing: 24 h (A2/A1) vs immediately (A3); build immediately with 24 h alarm. Not built |
| K-17 | Cash withdrawal limit **NPR 500,000 per transaction** (Nepal Gazette, effective 15 Jan 2026); more only on reasoned application | A1 §IV | VERIFIED via A1 — with savings module |
| K-18 | Records **≥ 5 years** after relationship ends (also electronic); keep until investigation/litigation closed | A2 §20; A1 §V | VERIFIED — KYM versions/documents are append-only; retention/legal hold not automated |
| K-19 | Fire-proof storage for documents if assets ≥ Rs 5 crore | A2 §20(2)(ग) | VERIFIED — physical; n/a to software |
| K-20 | Annual AML risk report to NCRA within 2 months of FY end; Schedule-3 data by end of Shrawan; compliance audit report within 6 months | A2 §7(2)-(3), §21(6) | VERIFIED — reports not built |
| K-21 | Board reviews AML at least every 6 months; compliance officer details to FIU and NCRA | A2 §17 | VERIFIED — not built |
| K-22 | Members must report KYM changes within **35 days** | A2 Sch-1 ज | VERIFIED — new KYM version on update |
| K-23 | Two existing members recommend the applicant | A2 Sch-1 ञ | VERIFIED; **PROPOSED** enforcement only once the cooperative has ≥ 2 active members |

Interpretations to confirm: which KYM fields are mandatory (the form does not say; we require
the identity, family, occupation, address, purpose, income band and self-declaration fields);
whether "dual membership" entries on the form are compatible with Act s.32 (we keep both the
declaration and the list).

## 11. Next research actions

1. ~~Read A1, A2~~ (done 2026-10-16). Still to read: A5 inspection procedure, X1 FIU TTR/STR guidelines (goAML formats).
2. Obtain Cooperative Rules 2075 current text (retention, protected-capital distribution, fees).
3. Have a professional resolve every CONFLICT and CHECK SCAN row.
4. Obtain regulator formats: chart of accounts / financial statements, MIS, CIC, goAML.
5. Re-check NCRA "co-operative criteria" and "guidance" pages before each module starts.
6. Obtain the official BS 2084 calendar before 2027-04-13 (last loaded date) and before closing FY 2083/84.
