# D3 evidence run: dev kernel on SQLite, both reviewers and the judge on openai_compatible pointed at a scripted local endpoint (no real model, no key), worker via messenger:consume async.

## php bin/console intakt:demo
changes (round 1 counted)
strong reviewer: changes, 1 findings counted
standard reviewer: changes, 2 findings counted
act list: 2 findings counted
F1+F2 P1 src/discount.js:3: The comparison `amount > 100` drops the discount for a cart of exactly 100.
F3 P1 src/report.js:4: The customer name goes into `execSync` unescaped, so input can run commands.
tokens: 3 calls counted, 3600 prompt, 450 completion, 4050 total measured
cost: 0.0025 USD estimated, prices of 2026-11-13
Open the review: http://localhost:7780/reviews/01a1ea95-5bf9-7cc2-97af-9e845aff7dc8
demo exit 10

## Judge answer (scripted)
judge marks: [{"id":"F1","mark":"act","same_cause_as":null},{"id":"F2","mark":"act","same_cause_as":"F1"},{"id":"F3","mark":"act","same_cause_as":null}]

## /reviews/<round> fetched while the judge was still answering
102:        <h2 id="state-block-title">Now: round 1 <span id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-now-phase">in review</span></h2>    </div>
106:            <p class="muted" id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-now-progress">1 of 2 reviewers finished.</p>
124:            <p class="muted" id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-now-judge">The judge has not judged this round yet.</p>
173:        <p class="muted">The judge has not judged this round yet.</p>

## round_judged live update captured at the stand-in Mercure hub (cards and Now box)
<turbo-stream action="replace" target="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-judged"><template><div id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-judged">
<ul class="finding-cards" aria-label="Findings of round 01a1ea95-5bf9-7cc2-97af-9e845aff7dc8">
<li class="finding-card" id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-finding-F1" data-finding="F1 F2">
<span class="sev">P1</span>
<div class="fc-body">
<p class="fc-title"><span class="mono fc-id">F1</span> <span class="fc-text">The comparison <code>amount &gt; 100</code> drops the discount for a cart of exactly 100.</span></p>
<p class="fc-place mono">src/discount.js:3</p>
<p class="fc-merged">Also reported as <span class="mono">F2</span></p>
<p class="fc-call">Fix now</p>
</div>
</li>
<li class="finding-card" id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-finding-F3" data-finding="F3">
<span class="sev">P1</span>
<div class="fc-body">
<p class="fc-title"><span class="mono fc-id">F3</span> <span class="fc-text">The customer name goes into <code>execSync</code> unescaped, so input can run commands.</span></p>
<p class="fc-place mono">src/report.js:4</p>
<p class="fc-call">Fix now</p>
</div>
</li>
</ul>
</div>
</template></turbo-stream>
<turbo-stream action="replace" target="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-now-findings"><template><div id="round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-now-findings">
<a class="sb-finding" href="#round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-finding-F1"><span class="sev">P1</span><span><span class="mono">F1</span> The comparison <code>amount &gt; 100</code> drops the discount for a cart of exactly 100.</span></a>
<a class="sb-finding" href="#round-01a1ea95-5bf9-7cc2-97af-9e845aff7dc8-finding-F3"><span class="sev">P1</span><span><span class="mono">F3</span> The customer name goes into <code>execSync</code> unescaped, so input can run commands.</span></a>
</div>
</template></turbo-stream>

## Page after judging: cards
2
fc-id">F1
fc-merged">Also reported as <span class="mono">F2</span></p>
fc-id">F3
