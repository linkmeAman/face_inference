<section class="browser-face-panel" ng-show="automaticFace.open" ng-cloak role="dialog" aria-modal="true" aria-labelledby="automaticFaceTitle" ng-keydown="automaticFaceKeydown($event)">
 <div class="browser-face-dialog" tabindex="-1" ng-class="{'is-attendance': automaticFace.capture_mode == 'attendance'}">
  <div class="browser-face-header">
   <h3 id="automaticFaceTitle"><span ng-bind="automaticFaceTitle"></span><span class="browser-face-title-name" ng-if="automaticFaceSubject"> — <span ng-bind="automaticFaceSubject"></span></span></h3>
  </div>
  <p class="browser-face-subtitle" ng-if="automaticFaceContext && automaticFace.capture_mode == 'attendance'" ng-bind="automaticFaceContext"></p>
  <p class="browser-face-instruction" ng-if="!automaticFace.result_ready" ng-bind="automaticFaceInstruction"></p>
  <div class="browser-face-camera" ng-show="!automaticFace.result_ready"><video id="automaticFaceVideo" autoplay muted playsinline></video><canvas id="automaticFaceOverlay" aria-hidden="true"></canvas>
   <svg class="browser-face-progress-ring" ng-show="!automaticFace.result_ready && automaticFace.progress > 0 && automaticFace.capture_mode != 'attendance' && automaticFace.guide" ng-style="{left:(automaticFace.guide.x-automaticFace.guide.radius)+'px',top:(automaticFace.guide.y-automaticFace.guide.radius)+'px',width:(2*automaticFace.guide.radius)+'px',height:(2*automaticFace.guide.radius)+'px'}" viewBox="0 0 100 100" role="progressbar" aria-label="Face capture observations" aria-valuemin="0" aria-valuemax="100" ng-attr-aria-valuenow="{{automaticFace.progress*100}}">
    <circle cx="50" cy="50" r="50" pathLength="100" fill="none" stroke="#16a34a" stroke-width="3" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-dasharray="100" ng-attr-stroke-dashoffset="{{100-100*automaticFace.progress}}" transform="rotate(-90 50 50)"/>
   </svg>
  </div>
  <div class="browser-face-feedback" ng-class="automaticFaceStatusClass()" role="status" aria-live="polite" aria-atomic="true">
   <p ng-bind="automaticFace.status"></p>
   <p ng-if="automaticFace.recognized_name && automaticFace.capture_mode == 'attendance'" class="browser-face-context" ng-bind="automaticFace.recognized_name"></p>
  </div>
  <p class="browser-face-note" ng-if="automaticFace.result_ready && automaticFace.previous_reference_preserved">Not saved. The earlier saved face is unchanged.</p>
  <div class="browser-face-actions">
   <button type="button" class="btn btn-default browser-face-switch" ng-show="automaticFace.can_switch_camera && automaticFace.busy" ng-disabled="automaticFace.camera_switching || automaticFace.progress >= 1 || automaticFace.retry || automaticFace.request_pending" ng-click="switchAutomaticFaceCamera()"><i class="fa fa-refresh" aria-hidden="true"></i> Switch Camera</button>
   <span class="browser-face-actions-gap" aria-hidden="true"></span>
   <button type="button" class="btn btn-primary" ng-show="automaticFace.retry" ng-click="retryAutomaticFace()">Retry</button>
   <button type="button" class="btn btn-success" ng-if="automaticFace.capture_mode == 'attendance'" ng-click="closeAutomaticFace()"><i class="fa fa-check" aria-hidden="true"></i> Done Scanning</button>
   <button type="button" class="btn btn-default" ng-if="automaticFace.capture_mode != 'attendance'" ng-click="closeAutomaticFace()">Close</button>
  </div>
 </div>
</section>

<!-- Authorized diagnostics are outside the camera dialog, available after close. -->
<details class="browser-face-practice-diagnostics" ng-if="browserFacePilot && !automaticFace.open && automaticFace.diagnostics">
 <summary>Practice camera diagnostics</summary>
 <pre>{{automaticFace.health | json}}</pre><pre>{{automaticFace.diagnostics | json}}</pre>
</details>
