# Public face pipeline evaluation

Research only. Production and pilot installation are disabled.

Dataset: HuMInGameLab/ControlFace10K @ a03589de1a9e028b2d16fa1eb0e019a6930e817c; CC-BY-4.0. Kassi Nzalasse, Rishav Raj, Eli Laird, Corey Clark — SIG (2024), arXiv:2409.08345; subset selection only, images unchanged. https://creativecommons.org/licenses/by/4.0/

Usable images: 138/144; identities: 48; PHP parity queries: 106.

Model: sface-int8bq-fb143eea; preprocessing: yunet640-similarity5-rgb255-v1.

Development-only exploratory points (not deployable): {"DUPLICATE_THRESHOLD": 0.5469521753092808, "ATTENDANCE_THRESHOLD": 0.5667687778180779}

| Partition | Genuine pairs / mean | Impostor pairs / mean |
|---|---|---|
| development | 68 / 0.632521 | 2347 / 0.239112 |
| heldout | 64 / 0.589522 | 2214 / 0.223513 |

| Partition | Gallery | Known correct / queries | Known unmarked | Known wrong | Unknown accepted / queries | Ambiguous |
|---|---:|---|---:|---:|---|---:|
| development | 16 | 20/30 | 9 | 1 | 4/24 | 4 |
| heldout | 16 | 18/30 | 12 | 0 | 1/22 | 5 |

Detailed pair rates, duplicate errors, uncertainty, top-two margins and development tradeoffs are in the JSON report.

Limitations:

- Synthetic adult ages 25/50/65; no children or real camera visits.
- Three generated pose views per identity are correlated, not independent live captures.
- Gallery references use one image; this does not validate five-frame enrollment aggregation.
- Identification metrics condition on quality-approved images; six excluded images remain separate capture failures.
- Three identical query vectors test PHP API shape only; each query remains one image trial.
- Wilson intervals are descriptive image-level intervals; within-identity correlation makes them optimistic.
- No public threshold may enable pilot or production matching.
- Top candidate is reported for research; ambiguous accepted candidates remain unmarked.
