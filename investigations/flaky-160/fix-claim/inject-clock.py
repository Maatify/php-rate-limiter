#!/usr/bin/env python3
# Applies the T -> T + 1 clock injection to the ORIGINAL test (testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence)
# so the claim reads one second after the block. Everything else in the test is untouched.
import sys
p = sys.argv[1]
s = open(p).read()
a = "    public function testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence(): void\n    {\n"
b = a + "        $t = time();\n        $injected = new FixedClock('@' . $t);\n        $this->store = new RedisFullCapabilityStore($this->executor, $this->namespace, $injected);\n"
assert s.count(a) == 1
s = s.replace(a, b)
c = "        self::assertNull($duringBlock->postPunishmentReentry);\n\n        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));\n"
d = "        self::assertNull($duringBlock->postPunishmentReentry);\n\n        $injected->setNow(new \\DateTimeImmutable('@' . ($t + 1)));\n        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));\n"
assert s.count(c) == 1
s = s.replace(c, d)
open(p, 'w').write(s)
