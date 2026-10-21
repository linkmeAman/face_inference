# Face enrollment and attendance process and decision guide

## Purpose and main conclusion

Reliable output in this system means more than detecting a face or displaying a success message. It means capturing suitable observations, producing a stable face signature, making the right enrollment or attendance decision, saving that decision, and recovering safely when something interrupts the process.

The approach taken was to keep the user experience simple while placing checks around the decisions that could produce an incorrect record. A person looks toward the camera; the system performs the capture and comparison automatically. The person is not asked to turn their head, blink, take photographs, approve frames, or complete a guided sequence.

This guide explains the steps taken, the decisions behind them, and how to repeat the process. It does not cover programming, installation commands, software structure, or implementation details. A “face signature” means the numerical description of a face used for comparison, rather than a saved photograph.

The completed validation demonstrates functional enrollment, duplicate rejection, and attendance using real face recognition and persistent records with public-photo camera inputs. It does not demonstrate production recognition accuracy or protection against photographs and replayed videos. Live-person calibration and physical-device testing remain necessary before operational use.

## 1 Define the desired output before changing the process

### What was done

The objective was reduced to three observable outcomes:

1. A new person is enrolled automatically after suitable face observations are collected.
2. A face meeting the duplicate criterion cannot create another enrollment under a different person entry.
3. A registered person is recognized automatically and marked once in the selected attendance session.

The ordinary capture experience was kept separate from operator preparation. An authorized operator may select the person or attendance session beforehand; the person being captured should not need to operate the recognition process.

### Why this decision matters

Without a clear definition of the final output, a system can appear complete merely because the camera starts or an outline appears. Those are intermediate events. The useful output is a confirmed enrollment or attendance record, with uncertainty handled safely.

Removing unnecessary capture interactions also reduces opportunities for abandonment, confusion, and failed retries. However, simplicity by itself does not improve recognition accuracy. Its purpose is to remove unnecessary effort while preserving the checks that determine whether an observation is usable.

### How to replicate

Write the expected result for each workflow in plain language. Include what must not happen: no record for an unknown face, no second attendance record in the same session, and no success before the save is confirmed. Treat those conditions as acceptance requirements, not optional polish.

## 2 Inspect the existing behavior and preserve what already works

### What was done

The existing camera, face detection, alignment, recognition, enrollment, attendance, persistence, access controls, error handling, and validation evidence were reviewed. Working parts were retained. The continuation focused on demonstrated recovery and verification gaps rather than replacing the recognition method or rebuilding the product.

The previous guided enrollment experience was removed so that users would not encounter a competing capture process. The old entry point now leads to the same automatic experience.

### Why this decision matters

Replacing a working part introduces new uncertainty. It can change recognition behavior, invalidate previous evidence, or create additional user paths that are difficult to test consistently. Retaining a stable recognition method also makes it possible to compare enrollment observations with later attendance observations in the same way.

### How to replicate

Observe each stage before deciding it needs replacement. Classify it as verified, broken, incomplete, or untested. Change a working part only when a specific defect or limitation has been demonstrated. Maintain one ordinary enrollment process, not an old guided process alongside a new automatic one.

## 3 Separate visibility quality identity and liveness

Four different questions govern the process:

| Question | Meaning | Appropriate response |
|---|---|---|
| Is a face visible | A face was detected in the camera view | Show its position and continue assessing it |
| Is the observation usable | The face is sufficiently clear, large and well exposed | Retain suitable observations and ignore unsuitable ones |
| Does the face match an identity | The signature meets the relevant identity criterion | Accept only when the required evidence is consistent |
| Is this a live person | The observation is not a photograph or replay | Do not claim this without separately validated defenses |

A face outline answers only the first question. A clear photograph may pass quality checks and produce a matching signature. Therefore, neither good lighting nor automatic collection proves liveness.

This distinction prevents two common mistakes: treating detection as recognition, and treating a usable image as proof of a live person. When replicating, keep these checks and their claims separate. Do not add a blink instruction and assume it solves presentation attacks.

## 4 Make the camera view accurately represent the captured face

### What was done

The preview and detected outline were made to agree with the actual camera dimensions. The front-camera preview appears mirrored for a familiar user experience, while the observations used for recognition are not mirrored. The displayed outline follows the mirrored preview. The image proportions are preserved rather than stretched or cropped unpredictably.

Portrait camera dimensions and an offset face were checked using controlled inputs. Physical camera rotation behavior still requires real-device validation.

### Why this decision matters

If the outline and the face disagree, users may move unnecessarily or believe that detection has failed. If the recognition image changes orientation or proportions between enrollment and attendance, the comparison may become less stable even though both previews look plausible.

### How to replicate

Check a centered face and a face positioned to either side. Check portrait and landscape views. Confirm that the outline remains attached to the face and that the face does not look stretched. Then repeat these checks on the physical cameras intended for use; a simulated portrait input is not proof that a phone handles rotation correctly.

## 5 Standardize the face observation before comparing identities

### What was done

Each suitable face was positioned consistently using facial reference points before its signature was generated. Enrollment and attendance use the same recognition method and the same preparation rules. One real recognition model, SFace, was retained rather than substituting fabricated signatures or changing methods without a demonstrated blocker.

The generated signatures were checked for valid values and made comparable on a common scale. The real recognition output was also compared against a reference implementation using the same model; the agreement check passed for the tested input.

### Why this decision matters

The same person can occupy different parts of the image or appear at a slightly different angle. Standardizing the observation reduces avoidable variation in how the face is presented to recognition. Keeping the method unchanged ensures that later attendance signatures can be meaningfully compared with enrollment signatures.

These measures support consistency; they do not prove that two different people will always be distinguishable. That remains an accuracy question requiring representative data.

### How to replicate

Use one defined recognition procedure for enrollment, duplicate detection, and attendance. Confirm actual recognition output, not just successful model loading. If the procedure changes, treat existing identity comparisons and previous calibration as potentially invalid until compatibility has been established or enrollment and calibration are repeated.

## 6 Reject unsuitable observations before building an identity record

### What was done

Automatic collection applies the following practical checks:

| Condition | Capture decision | Reason |
|---|---|---|
| No face | Wait and clear incomplete collection | There is no usable face to describe |
| More than one face | Pause and clear incomplete collection | Observations could be mixed between people |
| Face too small | Ask the person to move closer | Too little facial detail is available |
| Severe blur | Ignore the observation | Blurred detail can produce an unstable signature |
| Excessive darkness | Ignore the observation | Important facial features may be lost |
| Excessive brightness or clipping | Ignore the observation | Overexposure removes useful detail |
| One suitable face | Continue automatic collection | The minimum capture conditions are met |

These are basic usability filters. Their current settings are provisional and need review on actual deployment cameras. No pose, blink, eye-opening, or centering sequence is required for ordinary enrollment.

### Why this decision matters

More observations are not necessarily better. Adding poor observations can weaken the enrollment description and increase rejection or confusion later. Pausing when multiple faces appear is especially important because a good-quality observation of the wrong person is still the wrong input.

### How to replicate

Test each condition independently and confirm that unusable observations do not increase capture progress or create attendance. Keep feedback short and specific. A persistent lighting or blur problem should lead to a recoverable explanation, not pressure to save a lower-quality enrollment.

## 7 Collect several spaced observations automatically

### What was done

The system collects five usable observations for a capture batch. Retained observations are spaced by at least 350 milliseconds. It does not submit every camera image for identity checking, and it does not allow an unlimited backlog of old observations.

The camera preview remains separate from the more expensive recognition work. Old pending work is limited rather than allowed to accumulate while the person moves or leaves the view.

### Why this decision matters

A single observation can be affected by a brief movement, poor exposure, or an unstable crop. Several observations provide a basis for checking consistency. Spacing them reduces redundant sampling of nearly identical moments and limits unnecessary processing.

Five observations are a practical collection choice used here, not a proven universal optimum. Likewise, five observations from one short capture are not five independent accuracy trials. They may all share the same lighting, camera, and appearance.

### How to replicate

Start with the same five-observation procedure if reproducing this system. Measure completion time and failed-capture frequency on your actual cameras. Change the collection policy only when evidence shows a problem, and recheck both enrollment and attendance afterward. Do not increase the count merely because “more data” sounds safer.

## 8 Build one stable enrollment description

### What was done

Collected signatures were examined for consistency. Obvious outliers were removed using the batch's own pattern rather than adding another identity acceptance threshold. At least three retained observations are required for the final aggregation. The accepted signatures are averaged and put back on the common comparison scale.

Only the completed enrollment description is submitted for duplicate checking. Temporary collection is not saved as a valid enrollment.

### Why this decision matters

Averaging suitable observations reduces dependence on one accidental moment. Removing an obvious outlier prevents a markedly different observation from pulling the final description away from the main group. Not saving partial enrollment also makes retries possible without leaving a misleading or incomplete identity record.

The limitation is important: several consistently bad observations can still produce a bad description. Aggregation does not replace quality checks, representative calibration, or confirmation that only one person supplied the observations.

### How to replicate

Check that repeating the aggregation of the same observations produces the same result. Test a batch containing an obvious unusual observation. Confirm that insufficient or inconsistent collection leads to a fresh attempt, not a silent fallback to one photograph. Confirm enrollment only after the record is actually saved.

## 9 Check duplicates once against the authoritative enrollment records

### What was done

The completed enrollment description is compared centrally with existing enrolled descriptions. The closest match is assessed against the duplicate criterion. A qualifying duplicate is rejected, and an authorized operator can see the existing matching identity. Rejected capture does not create a valid face enrollment.

The duplicate decision and saving are protected against simultaneous enrollment attempts. Repeated delivery of the same submission returns the original outcome rather than creating another enrollment.

### Why this decision matters

Checking every camera frame would add unnecessary work and base decisions on incomplete observations. Checking only inside one browser would miss enrollments created elsewhere and would lose protection after refresh. A central decision provides one authoritative answer.

Simultaneous requests create a separate risk: two attempts may each appear new if they are checked independently before either is saved. The decision must therefore remain protected through the save, not merely during comparison.

### How to replicate

Enroll Person A, then attempt to enroll the same face under another person entry. Enroll a genuinely different Person B. Repeat after rejection. Test two simultaneous enrollment attempts and a lost response followed by retry. Check both the displayed result and the number of saved face enrollments. A second person entry may exist without a face enrollment; it must not be mistaken for a successful biometric registration.

## 10 Require consistent attendance evidence within the selected session

### What was done

Attendance collects a short batch without asking the person to choose their own identity. Every observation must identify the same single eligible person. Unknown observations, conflicting identities, and cases in which more than one identity meets the acceptance criterion do not create attendance.

Matching is restricted to the selected attendance population. In this system, the default population is the set of persons present when the session is opened. A session opened before a new person is added does not automatically include that person. Closed sessions reject new attendance.

### Why this decision matters

The highest similarity is not always an adequate reason to accept a face. An unknown person still has a “closest” enrolled person, and two similar-looking people may both appear plausible. Requiring a single qualifying identity and agreement across observations avoids assigning attendance merely because one candidate ranks first.

Session restrictions also prevent recognition from marking someone who is not eligible for that attendance context. This is a permission decision as well as an identity decision.

### How to replicate

Test a registered eligible person, an unknown person, an enrolled but ineligible person, conflicting observations, and an ambiguous comparison. If people are added after a session opens, use a new session or explicitly review its population. Never resolve uncertainty by arbitrarily selecting the highest-ranked identity.

## 11 Prevent repeated records and repeated notifications

### What was done

Once attendance is confirmed, the browser suppresses further matching submissions for that recognized person in that session. It shows the success briefly and then waits for the next person instead of announcing the same identity repeatedly. The persistent records independently enforce one attendance record per person per session.

### Why this decision matters

The browser can reduce repeated work and visual noise, but it cannot be the final protection. A browser refresh, a second operator screen, or a network retry can bypass a local memory of who was already marked. The saved attendance records must still prevent duplicates.

### How to replicate

Keep the same person in view after success. Refresh the browser and recognize that person again. Try from another screen and retry a request whose response was lost. Each case should leave exactly one attendance record in that session. The same person should be allowed one separate record in another authorized session.

## 12 Make interruption recovery preserve the truth of the result

### What was corrected

The continuation found and corrected several problems that could make a functioning recognition process appear broken or produce misleading feedback.

| Situation | Correct behavior | Reason |
|---|---|---|
| The server does not respond | End each attempt after ten seconds; retry up to three times with the same request identity | Prevent indefinite waiting without creating duplicate actions |
| The response is lost after saving | Retry safely and retrieve the committed outcome | A missing response does not mean the save failed |
| The person cancels | Stop capture and retry activity; ignore results belonging to the cancelled attempt | An earlier attempt must not complete a new capture screen |
| The camera disconnects | Stop capture and show a reconnect and retry message | A frozen preview should not look like active collection |
| The face disappears during identity checking | Preserve the processing status and completed progress | Collection is finished; the pending decision is a different stage |
| Saving succeeds but refreshing the list fails | Keep the confirmed success and show a separate refresh warning | A display refresh failure must not be reported as a failed save |
| The browser or application restarts | Preserve enrollment and attendance; require sign-in again after browser refresh | Records should not depend on the camera screen remaining open |

Cancellation cannot undo a save that was already committed. The correct response is to stop subsequent work and allow the operator to check the saved status, not falsely promise that cancellation erased every action.

### How to replicate

Interrupt the process during camera permission, collection, recognition, submission, and the final display refresh. Test the important timing boundary: save succeeds, but the operator does not receive the response. After recovery, compare the screen with the saved records. There must be no indefinite waiting, stale success from an older attempt, or duplicate record from retry.

## 13 Keep access and biometric protection outside the capture burden

### What was done

Operator access controls were retained. The local account and credentials were configured and checked, and the encryption key was kept stable. Normal views do not expose stored face signatures. Stored signatures are encrypted, ordinary capture does not retain raw face photographs, and deletion permits a new enrollment while attendance history remains.

The deliberate calibration export is an exception to ordinary handling: it saves sensitive signatures locally with consent. Those exports need private storage and a defined deletion schedule.

### Why this decision matters

Removing unnecessary capture steps is different from removing authorization. Unrestricted enrollment or attendance access could expose identity information or allow unauthorized records. These safeguards can be handled by the operator without making the person perform a complicated capture sequence.

A stable encryption key is also necessary for continuity. Replacing or losing it can make existing encrypted face records unreadable. Therefore, resetting access or restarting the application must not silently replace that key.

### How to replicate

Give the operator a usable account before handoff. Verify that unauthorized users cannot enroll, mark attendance, view identity details, or delete templates. Keep keys and biometric exports private; back up the key and encrypted records separately. Verify deletion and re-enrollment, including the fact that attendance history is retained rather than silently erased.

## 14 Separate functional verification from recognition accuracy

### What was done and what it proves

The system was checked at three levels: individual processing and decision checks, saved-record and access checks, and complete browser workflows using the real recognition method and real saved records.

The final automated results were 32 passing processing and interface checks, 18 passing application and record checks, and successful complete camera and product workflow suites. One separate alignment check requiring a consenting person's photograph was not run. Two distinct public face fixtures were used for the browser identity workflows.

| Evidence | Conclusion it supports | Conclusion it does not support |
|---|---|---|
| Actual recognition output agrees with a reference for the tested input | The tested preparation and recognition procedure produce real, consistent output | Accuracy for the intended attendance population |
| Two public faces enroll and their attendance works | The complete enrollment and attendance process is connected and functional | General performance across people, cameras and appearance changes |
| An unenrolled public face is rejected | Unknown rejection works for that tested case | A deployment-wide false acceptance rate |
| Simultaneous attempts and lost-response retries leave one record | Duplicate record protections work in the tested conditions | Identity accuracy or liveness |
| Records survive application restart and browser refresh | Persistence and recovery work | Mobile camera compatibility |
| Narrow-screen layout and portrait simulated input pass | Those display and geometry cases work in Chrome | Physical iPhone Safari or Android camera validation |

### Why this decision matters

Functional tests answer whether the process does what it is supposed to do with controlled inputs. Accuracy evaluation answers how often identity decisions are right across a realistic population. Treating the first as proof of the second would turn a successful demonstration into an unsupported operational claim.

### How to replicate

Use controlled examples to verify the process, including at least two known identities and an unknown identity. Keep real recognition and real saves in the complete workflow test; simulated successful identity responses cannot establish integration. Then run a separate live-person evaluation using independent observations, devices, and conditions.

## 15 Calibrate the two identity decisions with representative observations

### Decision taken

Only two primary identity acceptance criteria are used: one for duplicate enrollment and one for attendance. They are separately configurable because the consequences of an error differ. A mistaken duplicate finding prevents a genuine new person from enrolling; incorrect attendance acceptance can attribute presence to the wrong person.

The current development installation uses an upstream SFace example baseline of 0.363 for both comparisons. Controlled static-fixture workflow tests use 0.99. These values allow explicit development tests; neither has been validated as an operational recommendation for this camera process or population. Production acceptance remains gated by calibration evidence.

### Reasoning

A higher similarity criterion usually makes acceptance more restrictive, but the useful trade-off depends on the actual observations and population. Choosing a familiar number or increasing it after one bad result is not calibration. The enrollment and attendance preparation must match the preparation used to collect calibration evidence.

| Decision | Wrong acceptance | Wrong rejection |
|---|---|---|
| Duplicate comparison | Two different people are treated as the same, blocking a genuine new enrollment | The same person is treated as different, permitting another enrollment |
| Attendance comparison | An observation is accepted as the wrong enrolled person, potentially creating incorrect attendance | A genuine enrolled person remains unmarked and needs a retry |

Review these consequences with the operator before choosing either criterion. A system that rejects almost everyone can look safe while failing its attendance purpose. A system that accepts readily can look convenient while creating wrong records. The aim is an evidenced, acceptable balance, not the largest similarity number or the highest demonstration success rate.

### Replication procedure still required before operational acceptance

1. Obtain informed consent and explain the purpose, use, storage, and deletion of face observations.
2. Assign each consenting person one consistent identity label. Do not use a different label when the same person changes device or returns another day.
3. Collect observations from independent visits, not only consecutive frames from one short capture.
4. Include the intended cameras, normal lighting variation, glasses and realistic appearance changes. Include consenting similar-looking people where available.
5. Preserve visit, device, and lighting labels so difficult conditions can be analyzed separately.
6. Compare observations of the same person and observations of different people. Examine where the two groups overlap.
7. For each candidate criterion, count wrong acceptances and wrong rejections. Report identities, observations and comparison counts, not just a percentage.
8. Review duplicate and attendance criteria separately against the consequences of each error.
9. Set aside independent observations and people where practical to validate the chosen criteria. Do not judge success only on the observations used to choose them.
10. If the evidence is insufficient or the trade-off is unacceptable, retain the calibration block. Do not replace missing evidence with a claimed accuracy figure.

The current collection guidance starts with at least 20 consenting identities and at least 100 observations across repeat visits. These are starting coverage requirements, not proof of sufficient accuracy. Comparisons that reuse the same people and observations are correlated; a large pair count is not the same as a large number of independent trials. No representative live dataset has been supplied, so this operational calibration step remains outstanding.

## 16 Measure the experience rather than promising a speed

### What was measured

In the final desktop Chrome workflow with public-photo simulated camera input, camera start to confirmed enrollment took 3.698 seconds and camera start to confirmed attendance took 3.208 seconds. The last face detection took approximately 29 milliseconds for enrollment and 24 milliseconds for attendance. The last recognition operation took approximately 64 and 61 milliseconds respectively.

Other continuation runs took approximately 4.465 and 6.671 seconds for enrollment. A separate camera check took approximately 6.810 seconds while other checks ran concurrently. The observed variation matters: the fastest result is not a reliable promise for every person or device.

### Why this decision matters

A responsive preview and a quick-looking progress indicator can hide slow processing or repeated requests. Measuring the complete time to a confirmed result captures the experience the operator actually cares about. Breaking that time into stages helps distinguish camera startup, recognition work, and final identity processing.

### How to replicate

Record the actual device, browser, camera, lighting, result and elapsed time for repeated attempts. Separate first use from subsequent use. Include failures and retries instead of reporting only successful fast captures. Measure a sustained session as well as individual attempts. Do not treat a page-memory reading as total application memory; worker and camera processing may not be included.

No physical mobile timing, sustained processing load, or total-memory claim has been verified here.

## 17 Follow a repeatable validation sequence

Use the following sequence when reproducing the process. Items involving live subjects, calibration, or physical devices are required next-stage checks, not completed evidence from this project.

1. **Define the result and consent.** Identify the person/session, permitted population, purpose and retention expectations. Confirm that automatic capture is the intended interaction.
2. **Verify camera readiness.** Test permission, preview, outline position, proportions and interruption feedback.
3. **Verify usable observation selection.** Try no face, one face, two faces, a small face, blur, darkness and overexposure. Unsuitable observations must not advance enrollment or create attendance.
4. **Verify consistent preparation.** Confirm that enrollment and attendance use the same face preparation and recognition procedure.
5. **Verify automatic collection.** Confirm several spaced observations, visible progress and no manual photo or pose instruction.
6. **Verify aggregation and retry.** Confirm that an unusual observation does not dominate the enrollment description and that a failed attempt can start fresh.
7. **Verify enrollment save.** Enroll Person A and confirm the saved enrollment after leaving the capture screen.
8. **Verify duplicate rejection.** Attempt the same face under a different person entry and confirm that no second face enrollment is saved.
9. **Verify distinct enrollment.** Enroll Person B and check that both intended enrollments exist, subject to the calibrated duplicate criterion.
10. **Open a suitable attendance session.** Check its eligible population before recognition.
11. **Verify attendance decisions.** Recognize A and B; test an unknown person and an ineligible person. Uncertainty must remain unmarked.
12. **Verify uniqueness.** Keep a known person in view, retry after a lost response, and revisit after browser refresh. Confirm one attendance record for that person/session.
13. **Verify recovery boundaries.** Cancel during capture and identity checking. Interrupt the camera and connection. Check whether a save already occurred before retrying.
14. **Verify persistence and deletion.** Restart the application, revisit saved status, delete a selected face enrollment, and confirm a fresh enrollment is possible without silently erasing attendance history.
15. **Evaluate live accuracy.** Collect independent representative observations, calibrate both criteria and validate the resulting decisions on held-out observations.
16. **Validate actual target devices.** Repeat the normal and interruption workflows on desktop Chrome, Android Chrome and iPhone Safari. A resized desktop window does not satisfy this step.
17. **Review the permitted use.** Confirm that measured accuracy, privacy handling, operational support and spoofing limitations are acceptable before enabling operational attendance.

## 18 Keep an evidence record that another person can audit

For each validation attempt, record the scenario, device and browser, input conditions, expected outcome, observed outcome, saved-record count, elapsed time, and any recovery required. Record whether the camera source was a live consenting person or a controlled fixture. Avoid including photographs, face signatures, passwords, or keys in ordinary reports.

Use four result labels consistently:

- **Verified:** the specified check was performed and passed under the recorded conditions.
- **Failed:** the specified check was performed and the result was wrong.
- **Blocked:** a named external prerequisite prevents the check, such as a missing device or representative calibration data.
- **Not tested:** the check has not been performed; do not infer its result from a related test.

The current evidence verifies the controlled end-to-end workflow, record uniqueness, tested unknown and uncertain cases, recovery, persistence, and simplified desktop/responsive interface. Live-person enrollment and attendance validation, calibrated same-person and different-person decisions, and physical mobile validation remain blocked. Physical desktop camera behavior and sustained-load measurements have not been tested.

## 19 Lessons for better output generation

### Preserve the distinction between a capture and a decision

A good face observation is the starting point, not the final result. Quality selection, consistent preparation, identity comparison, authorization, and saving each answer a different question. Keeping those responsibilities separate makes failures easier to identify without inventing extra user steps.

### Prefer a small amount of suitable evidence to a large amount of poor evidence

Five usable observations with controlled sampling are more purposeful than processing every camera frame. The expected benefit is less redundant work and reduced influence from accidental poor observations. The real benefit must still be measured on the intended devices.

### Make uncertainty visible and nonproductive

An unknown, ambiguous or conflicting face should not create attendance. A timed-out capture should not create an incomplete enrollment. This is safer than producing a plausible identity merely to keep the workflow moving.

### Treat retries and refreshes as ordinary use

Connections fail, cameras disconnect, and users cancel. Protecting the saved outcome and keeping status truthful under these conditions is part of the recognition product, not optional error handling added afterward.

### Remove interaction overhead without removing necessary safeguards

Guided poses, manual shutters, frame approval and repeated success messages were unnecessary for the intended experience. Consent, operator authorization, uncertainty rejection, stable records and calibration were not. A minimal interface is successful when it hides unnecessary complexity without hiding consequential limitations.

### Use evidence to decide what may be claimed

The complete workflow now works under the tested development conditions. That is a meaningful result. It is not evidence that every live person will be recognized accurately, that an iPhone camera will behave identically, or that a photograph cannot be accepted. Replication should preserve this distinction rather than copy only the favorable result.

## Final readiness decision

The process is suitable for controlled development demonstrations and continued consent-based evaluation. It is not yet validated for operational attendance. The remaining steps are representative live-person calibration, independent accuracy validation, physical target-device testing, and review of the intended use against the absence of validated spoofing defenses.

The principal replication rule is to keep capture automatic, decisions evidence-based, records authoritative, and recovery truthful. Do not confuse a simple interaction with permission to skip the checks that protect the output.
