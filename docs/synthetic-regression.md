# Synthetic regression check

Run `python tools/evaluate_synthetic.py --check` from the repository. It uses Python
3.10 or newer (`pyproject.toml:10`), the bundled images and recorded vectors only;
it makes no network requests, writes no dataset files and does not run inference.
On the development machine the check took approximately 0.3 seconds; runtime
varies with storage and hardware. A nonzero exit reports missing, changed, malformed
or mismatched evidence. The manifest, inference report and evaluation report are
pinned by SHA-256 in the script; intentional updates require a separately reviewed
baseline and corresponding pin changes.

The manifest records the image paths, per-image hashes, identities and partitions
(`datasets/controlface10k-subset/manifest.json:2`, `:9`, `:11`). It identifies
HuMInGameLab/ControlFace10K revision `a03589de1a9e028b2d16fa1eb0e019a6930e817c`,
licensed CC BY 4.0. Credit: Kassi Nzalasse, Rishav Raj, Eli Laird and Corey Clark,
“SIG: A Synthetic Identity Generation Pipeline for Generating Evaluation Datasets
for Face Recognition” (2024), arXiv:2409.08345; subset selection only, images
unchanged; https://creativecommons.org/licenses/by/4.0/
(`datasets/controlface10k-subset/manifest.json:2`, `:6`).

The inference report records the model contract, asset hashes and per-image
embeddings (`datasets/reports/ccc-controlface-inference-20261009.json:8`, `:56`, `:61`, `:81`).
The pinned evaluation report contains the frozen exploratory thresholds and
held-out count baseline (`datasets/reports/ccc-controlface-evaluation-20261009.json:98`,
`:107552`, `:107598`, `:107659`). The check validates image hashes and inventory,
then reconstructs the archived evaluator's hash-ordered identity split, first
usable gallery view and query rules (`legacy-ccc-final:evaluation/public_dataset_evaluate.py:8-23`),
sequential cosine and unique-above-threshold decision rules
(`legacy-ccc-final:evaluation/decision_evaluation.py:12-18`, `:47-54`;
`src/face_inference/matching.py:23-34`). It compares both duplicate and attendance
count sets using the recorded thresholds (`legacy-ccc-final:evaluation/public_dataset_evaluate.py:45-58`).
This checks recorded-vector decisions; it does not reproduce inference, pairwise
statistics, confidence intervals or backend parity.

The held-out attendance result is 18/30 known correct, 12 unmarked and 1/22
unknown accepted (`datasets/reports/ccc-controlface-evaluation-20261009.md:18-21`).
The source explicitly limits the experiment to synthetic adult faces, correlated
views, single-image gallery references and quality-approved images; it does not
validate real camera visits or five-frame enrollment
(`datasets/reports/ccc-controlface-evaluation-20261009.md:25-34`). These results
are research only and do not establish live accuracy, pilot readiness or production
approval (`datasets/reports/ccc-controlface-evaluation-20261009.md:3`, `:33`).
