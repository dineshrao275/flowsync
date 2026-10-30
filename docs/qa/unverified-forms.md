# Forms that no role could submit successfully in the sweep

The role matrix shows what each perspective got. These are the forms where **no** perspective succeeded in the automatic sweep or in the scripted
workflow scenarios. For each: the refusal the application gave (taken from the most privileged attempt), what kind of rule it is, and the
automated tests that already submit it successfully. A refusal here is documented, not changed, unless the findings in `form-audit.md` say otherwise.

| Form | Refusal (best attempt) | Kind | Existing automated evidence |
|---|---|---|---|
| `DELETE api/billing/payment-methods/{paymentMethod}` | That card does not belong to this organisation. (admin) | Workflow state rule | PaymentMethodsTest.php |
| `DELETE api/hrms/onboarding/templates/{template}` | Cases are still running from this template. Retire it instead of deleting it. (admin) | Workflow state rule | HrmsLifecycleApiTest.php |
| `DELETE api/hrms/onboarding/templates/{template}/tasks/{task}` | Open cases are still running this item. Finish or cancel those cases first. (admin) | Workflow state rule | HrmsLifecycleApiTest.php |
| `DELETE api/hrms/payroll/components/{component}` | That is a starter head — it can be renamed, not repurposed. (admin) | Workflow state rule | HrmsCompensationApiTest.php |
| `DELETE api/hrms/payroll/payslips/{payslip}/adjustments/{adjustment}` | No query results for model [App\Models\Hrms\Payroll\PayslipAdjustment] 999999 (admin) | Needs a record in a state the data set does not contain | HrmsPayrollRunApiTest.php |
| `DELETE api/hrms/payroll/statutory/configurations/{configuration}` | No query results for model [App\Models\Hrms\Statutory\StatutoryConfiguration] 999999 (admin) | Needs a record in a state the data set does not contain | HrmsStatutoryConfigApiTest.php |
| `DELETE api/hrms/payroll/structures/{structure}` | That structure prices people — deactivate it instead of deleting history. (admin) | Workflow state rule | HrmsCompensationApiTest.php |
| `POST api/auth/2fa/challenge` | Your sign-in has expired. Enter your password again. (guest) | Other | TwoFactorTest.php |
| `POST api/auth/reset-password` | We can't find a user with that email address. (guest) | Workflow state rule | SecurityRegressionTest.php |
| `POST api/billing/payment-methods/complete` | App\Billing\Stripe\BillingProviderException: Stripe is not configured. @ app/Billing/Stripe/StripeClient.php:34 (admin) | Environment: payment provider not configured | PaymentMethodsTest.php |
| `POST api/billing/payment-methods/setup` | App\Billing\Stripe\BillingProviderException: Stripe is not configured. @ app/Billing/Stripe/StripeClient.php:34 (admin) | Environment: payment provider not configured | PaymentMethodsTest.php |
| `POST api/billing/payment-methods/{paymentMethod}/default` | That card does not belong to this organisation. (admin) | Workflow state rule | PaymentMethodsTest.php |
| `POST api/billing/portal` | The billing portal is not available for this payment provider. (admin) | Environment: payment provider not configured | BillingProviderFailureTest.php, StripeBillingTest.php |
| `POST api/billing/verify` | No query results for model [App\Models\Payment] 5 (admin) | Needs a record in a state the data set does not contain | BillingTest.php, ProrationTest.php, StripeBillingTest.php |
| `POST api/hrms/leave/requests/{leaveRequest}/reject` | A rejection needs a reason the requester can act on. (approver) | Workflow state rule | HrmsLeaveRequestApiTest.php |
| `POST api/hrms/offboarding/cases/{case}/clear` | This exit cannot be cleared yet: 3 assets still to return. (admin) | Workflow state rule | HrmsLifecycleApiTest.php |
| `POST api/hrms/offboarding/cases/{case}/complete` | Clear the exit first — completion signs off a signed clearance, not an open one. (admin) | Workflow state rule | HrmsLifecycleApiTest.php |
| `POST api/hrms/offboarding/cases/{case}/tasks/{task}/convert` | Only task-category items convert to project tasks. (admin) | Workflow state rule | HrmsCaseTaskConversionTest.php |
| `POST api/hrms/offboarding/cases/{case}/tasks/{task}/sync` | This item was never converted to a task. (admin) | Workflow state rule | HrmsCaseTaskConversionTest.php |
| `POST api/hrms/payroll/payslips/{payslip}/adjustments` | Only a run in review accepts adjustments. (admin) | Workflow state rule | HrmsPayrollRunApiTest.php |
| `POST api/hrms/payroll/statutory/profiles/{employee}/reveal` |  (admin) | Other | HrmsStatutoryProfileTest.php |
| `POST api/hrms/payroll/statutory/recompute` | Only a run in review accepts a statutory recompute. (admin) | Workflow state rule | HrmsStatutoryTdsApiTest.php |
| `POST api/hrms/payroll/statutory/tds-projects` | There is no TDS rulebook for this employee’s jurisdiction. (admin) | Workflow state rule | HrmsStatutoryTdsApiTest.php |
| `POST api/hrms/payroll/statutory/tds-projects/{project}/surrender` | No query results for model [App\Models\Hrms\Statutory\TdsProject] 999999 (admin) | Needs a record in a state the data set does not contain | HrmsStatutoryTdsApiTest.php (test_a_manager_projects_warns_and_surrenders) |
| `POST api/hrms/performance/feedback-requests/{feedbackRequest}/respond` | No query results for model [App\Models\Hrms\Performance\FeedbackRequest] 999999 (admin) | Needs a record in a state the data set does not contain | HrmsPerformanceApiTest.php |
| `POST api/impersonate/stop` | You are not impersonating any user. (admin) | Workflow state rule | ImpersonationHardeningTest.php, IsolatedProvisioningTest.php, SystemAdminTest.php, TenantTest.php |
| `POST api/my-subscription/switch` | This tenant is on a bundle plan. Move it to per-product plans first. (admin) | Workflow state rule | BillingProviderFailureTest.php, MySubscriptionTest.php, ScheduledPlanChangeTest.php |
| `POST api/plans/{plan}/sync` | Super administrator access is required for this action. (admin) | Other | StripeCatalogSyncTest.php (test_the_manual_resync_button_syncs_one_plan_for_the_super_admin_only, added by this audit) |
| `POST api/register` | Self-registration is currently disabled. (guest) | Other | AuthAuditTest.php, RegisterCardTest.php, RegisterTest.php, SystemAdminTest.php |
| `POST api/register/card` | Self-registration is currently disabled. (guest) | Other | RegisterCardTest.php |
| `POST api/register/complete` | Self-registration is currently disabled. (guest) | Other | RegisterCardTest.php |
| `POST api/tenants/{tenant}/intake/submit` | Super administrator access is required for this action. (admin) | Other | BillingConsistencyTest.php, ProductPlansTest.php, TenantIntakeTest.php |
| `PUT api/hrms/payroll/components/{component}` | That is a starter head — it can be renamed, not repurposed. (admin) | Workflow state rule | HrmsCompensationApiTest.php |
| `PUT api/hrms/payroll/statutory/configurations/{configuration}` | No query results for model [App\Models\Hrms\Statutory\StatutoryConfiguration] 999999 (admin) | Needs a record in a state the data set does not contain | HrmsStatutoryConfigApiTest.php |
| `PUT api/sso-settings` | Could not read the identity provider's configuration from that address. (admin) | Workflow state rule | SsoTest.php |
