# Rules of this codebase

These are not preferences. A change that breaks one is not merged; where a machine can check a rule, the
build does (`composer check`), and the rule says which test.

They were written after a review of the library found each of them broken somewhere. They apply to the
library and to any application built on it.

## 1. Names, not literals

1. **A value with a meaning is a typed constant, used by name.** A reason code, a state, a header, a TTL, a
   limit: declared once (`public const string REASON = 'payments_paused';`) and never retyped as a literal
   anywhere else, tests included. *Checked: `ArchitectureTest::testEveryConstantIsTyped`.*
2. **A closed set of values is declared as a set**: an enum, or constants plus the list of them
   (`Job::TRANSITIONS`, `Settlement::MAY_HAVE_MOVED`). Code compares against the set, not against its own
   copy of it.
3. **A number says its unit** in its name or its docblock: `TTL` in seconds, amounts in atomic units and as
   strings. Money is never a float.

## 2. Contracts

4. **Every file starts with `declare(strict_types=1);`** *Checked: `testEveryFileIsStrict`.*
5. **Every class is `final`.** The library is extended through interfaces and composition, never
   inheritance. *Checked: `testAClassIsFinal`.*
6. **A dependency is an interface**: PSR where one exists (PSR-3 log, PSR-6 cache, PSR-7/15/17/18 HTTP,
   PSR-14 events, PSR-20 clock), ours otherwise (`Facilitator`, `Scheme`, `PaymentLedger`, `JobStore`). A
   closure is accepted only next to an interface, for the caller that has to wrap it.
7. **The core never asks what a thing is; it asks the thing.** No `instanceof` on a concrete class and no
   test on a network's prefix outside that chain's own scheme. A new capability is a new optional interface.
8. **Nothing outside the library needs an `@internal` class.** What an integrator compares with or calls
   lives on a public class, and the guides never name an internal one.
   *Checked: `testThePublicApiNeedsNoInternalClass`, `testTheGuidesNameNoInternalClass`.*
9. **A docblock describes the method it sits on**: every `@param` is a real parameter, once.
   *Checked: `DocblockTest`.*
10. **Published API does not break before a major version.** A parameter keeps its position and name; a
    type may widen, not narrow; new parameters are optional and last. *Checked: `CompatibilityTest`.*
11. **The time comes from a clock that was passed in**, never from `time()` in code under test.
    *Checked: `testAClockIsAPsr20ClockOrAClosure`.*

## 3. Failing

12. **Fail closed.** When a check cannot answer (a list that cannot be read, a store that is down, a
    listener that throws), the answer is no. Letting something through is a setting someone chose by name
    (`OfacSdn::off()`, `Screening::ALLOW`, `MockFacilitator`), never a default and never a fallback.
13. **Configuration fails at deploy, not on a request.** Whatever a typo could silently weaken (a price, a
    tool name, a rule, a network) is validated when the object is built, and the application builds it in
    a deploy check (`PriceList`, `SensitiveRules`, `Networks::assertSupported`, `LedgerCheck`).
14. **Write the intention before the money can move, and never undo a payment because bookkeeping failed.**
    A crash between two steps must leave a record a reconciler can finish. After a settle, an error in a
    log, a store or a listener is reported (`PaymentFault`), not thrown.
15. **Our exceptions implement `X402Exception`.** A mistake of the caller throws PHP's own
    `\InvalidArgumentException` or `\LogicException`. Nothing is caught and dropped without a comment that
    says why it is safe. *Checked: `testEveryExceptionOfTheLibraryIsMarked`.*

## 4. Secrets and other people's input

16. **A secret parameter is `#[\SensitiveParameter]`**, and no secret, signature or buyer text goes to a
    log, an exception message or a stored reference. *Checked: `testNoSecretIsShownInAStackTrace`.*
17. **Whatever the other party wrote is untrusted**: a seller's offer, a buyer's payment, a facilitator's
    answer. Each is read field by field, by type, in one spelling; "it is usually well formed" is not a check.

## 5. Tests

18. **No code without a test in the same repository.** The library's tests do not live in an application.
19. **A test does not depend on today's date, on shipped data that changes, on the network, or on who runs
    it.** Dates are far from now or come from a pinned clock; a test that needs a service (Redis) is run in
    CI with that service, not skipped there.
20. **A bug gets a test that fails before the fix.** A limit that is accepted instead of fixed is written
    where the reader will look (the class's docblock, `docs/protections.md`), with what would fix it.

## 6. Changes

21. **Before a push: `composer check`, all of it green.** Tests, PHPStan, php-cs-fixer, the dependency
    analyser. CI runs the same on every supported PHP version.
22. **A decision is written down the day it is taken**, with its reason and what it costs (`UPGRADE.md` for
    what users must do, `ROADMAP.md` for what is left).
23. **Nothing is said to work that was not run.** A pull request says what was run and what was not.
