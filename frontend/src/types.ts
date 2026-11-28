export type User = {
  id: number
  name: string
  /** Empty for someone who signs in with their mobile number only. */
  email: string | null
  /** How this login is opened: a password, or a PIN for a login that reaches only the person's own record. */
  sign_in_with?: 'password' | 'pin'
  /** Signed in with a starting PIN the hospital handed out: nothing opens until they choose their own. */
  must_change_secret?: boolean
  /** The Google account they can sign in with, once joined. */
  google_email?: string | null
  role: string
  role_label: string
  can_manage_staff: boolean
  /** May see what consultants earn, set their share and fees, and record what is paid to them. */
  can_pay_consultants?: boolean
  can_consult: boolean
  /** May read clinical records: through their own clinical or lab work, or by being allowed to look. */
  can_view_clinical?: boolean
  can_run_lab: boolean
  can_approve_lab?: boolean
  can_run_pharmacy: boolean
  /** May open the pharmacy's screens: to work in them, or only to look. */
  can_view_pharmacy?: boolean
  can_manage_setup: boolean
  can_set_prices?: boolean
  /** May download lists and reports as a file. */
  can_export?: boolean
  /** Everything this person may do, by the permission's own name. */
  abilities?: Record<string, boolean>
  can_front_desk: boolean
  /** May scan a patient's old papers, check what was read from them and file them. */
  can_file_past_records: boolean
  can_ipd: boolean
  can_view_reports: boolean
  can_approve_ipd: boolean
  can_delete_bills: boolean
  /** Approves stock counts and corrections in the pharmacy, and sees what is missing. */
  can_audit_pharmacy: boolean
  can_change_price: boolean
  can_record_expenses?: boolean
  /** For an admin: something about the backups that needs doing, or null. */
  backup_alert?: string | null
  pad_hidden_sections?: string[]
  /** Menu tabs this person chose not to see. */
  hidden_tabs?: string[]
  /** This person's own theme and text size. */
  appearance?: { theme: string; text: 'small' | 'normal' | 'large' }
  branch: { id: number; name: string; city: string | null } | null
}

export type Staff = {
  id: number
  name: string
  email: string
  role: string
  gender: string | null
  phone: string | null
  department: string | null
  signature_text: string | null
  is_active: boolean
  /** What this person may do, after their own permissions are applied to their role. */
  abilities: Record<string, boolean>
  has_signature: boolean
  availability: Availability | null
  /** How the login is opened. "none": a doctor who is on the hospital's lists and does not sign in. */
  sign_in_with?: 'password' | 'pin' | 'none'
  /** Whether patients may book this doctor from the phone app. */
  book_online?: boolean
  /** A doctor's own OPD consultation fee, when they have one. */
  opd_fee?: number | null
  slot_minutes?: number | null
  /** A doctor's share of what is collected from their own patients, in percent for each counter. Sent to admins only. */
  share_front_desk?: number | null
  share_lab?: number | null
  share_ipd?: number | null
}

/** One permission that can be given to or taken from a person, and the roles that get it by default. */
export type Ability = { key: string; label: string; hint: string; roles: string[]; /** The heading it is listed under on the Staff screen. */ group?: string; /** Carries clinical authority, money or control: only an admin gives it on top of a role. */ sensitive?: boolean }

/** A doctor's usual hours for each day of the week; a day left out means no set hours. */
export type Availability = Partial<Record<'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun', { from: string; to: string }[]>>

export type RoleOption = { value: string; label: string }

export type Patient = {
  id: number
  uhid: string
  name: string
  gender: string
  date_of_birth: string
  dob_estimated: boolean
  age: number
  /** The age as read out: "12d", "8m" or "34y". */
  age_label: string
  title?: string | null
  /** Booked with only a name and number; the registration is still to be completed. */
  is_temporary?: boolean
  mobile: string
  mobile_alt: string | null
  email: string | null
  blood_group: string | null
  preferred_language: string | null
  address: string | null
  city: string | null
  pincode: string | null
  care_of: string | null
  occupation: string | null
  referred_by: string | null
  channel: string | null
  tag: string | null
  existing_id: string | null
  created_at: string
  /** When the patient was last seen, in the patients list only. */
  last_visit_at?: string | null
}

export type Doctor = { id: number; name: string; department: string | null; availability?: Availability | null; /** Minutes in one of this doctor's slots; empty to follow the hospital's. */ slot_minutes?: number | null }

export type Service = {
  id: number
  code: string | null
  name: string
  type: 'appointment' | 'other'
  price: number
  gst_percent: number
  priority: number
  doctor_id: number | null
  /** This doctor's usual OPD consultation: put in by itself when the doctor is chosen for a visit. */
  is_doctor_fee?: boolean
  doctor: { id: number; name: string } | null
  is_active: boolean
}

export type AppointmentStatus = 'booked' | 'arrived' | 'ongoing' | 'reviewed' | 'cancelled'

export type Appointment = {
  id: number
  scheduled_at: string
  duration_minutes: number
  status: AppointmentStatus
  /** A free follow-up: to the same doctor within the hospital's follow-up days of a consultation. */
  is_follow_up?: boolean
  /** On-going, paused while the patient is at the lab for the reports the doctor asked for. */
  awaiting_reports?: boolean
  token_no: number | null
  /** When the front desk marked the patient as here. */
  arrived_at?: string | null
  notes: string | null
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  doctor: { id: number; name: string }
  service: { id: number; name: string } | null
  /** What the doctor decided, once the visit pad has been saved. */
  consultation?: { id: number; completed_at: string | null; tests_advised: string | null; follow_up_on: string | null; refer_to: string | null; medicines_count: number } | null
  bills: { id: number; bill_no: string; kind: BillKind; total: number; paid: number; due: number; moved_to_ipd: number | null; lab_order: { id: number; order_no: string } | null }[]
}

/** Someone advised to come back whose date has passed without a visit. */
export type NotReturned = { id: number; patient: Appointment['patient']; doctor: { id: number; name: string } | null; follow_up_on: string; days_late: number; seen_on: string; diagnosis: string | null; booked_for: string | null }

export type FollowUp = {
  id: number
  follow_up_on: string
  completed_at: string
  diagnosis: string | null
  booked: boolean
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  doctor: { id: number; name: string }
}

export type CalendarSettings = { slot_minutes: number; slot_limit: number; morning_start: string; morning_end: string; evening_start: string; evening_end: string }

/** Days, or the same hours on each of those days, when a doctor is not seeing patients. */
export type DoctorBlock = { id: number; doctor_id: number; from_date: string; to_date: string; start_time: string | null; end_time: string | null; reason: string | null; doctor: { id: number; name: string } }

export type BillKind = 'appointment' | 'lab' | 'other'

export type BillItem = {
  id: number
  description: string
  quantity: number
  unit_price: number
  discount: number
  gst_percent: number
  amount: number
  tax: number
}

export type Payment = {
  id: number
  type: 'payment' | 'refund'
  amount: number
  mode: string
  reference: string | null
  created_at: string
  /** Set when the money was entered on a later day than the one it is counted on. */
  entered_at?: string | null
  receiver: { id: number; name: string } | null
}

export type Bill = {
  id: number
  bill_no: string
  kind: BillKind
  lab_order?: { id: number; order_no: string } | null
  subtotal: number
  tax: number
  discount: number
  /** Why a discount on this bill was given and who allowed it; empty when nothing is taken off. */
  discount_reason?: string | null
  discount_allowed_by?: string | null
  total: number
  paid: number
  due: number
  created_at: string
  /** Set when the bill was entered on a later day than the one it is dated. */
  entered_at?: string | null
  items: BillItem[]
  payments: Payment[]
  /** Set when the bill was cancelled as a mistake: it then counts for nothing, and its lines are kept only as a record. */
  cancelled_at?: string | null
  cancel_reason?: string | null
  canceller?: { id: number; name: string } | null
  /** What the patient has on deposit and could use against this bill. */
  deposit_balance?: number
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile' | 'address' | 'city'>
  appointment: { id: number; scheduled_at: string; doctor: { id: number; name: string } } | null
}

export type PrescribedMedicine = {
  id?: number
  /** In the medicines library: how many times it has been prescribed. */
  uses?: number
  medicine: string
  dose: string | null
  frequency: string | null
  timing: string | null
  duration: string | null
  instructions: string | null
}

export type Consultation = {
  id: number
  /** Results typed in from reports the patient brought. */
  investigations?: { name: string; result: string | null; date: string | null }[] | null
  weight_kg: number | null
  height_cm: number | null
  bp_systolic: number | null
  bp_diastolic: number | null
  pulse: number | null
  temperature_f: number | null
  spo2: number | null
  resp_rate: number | null
  quick_notes: string | null
  complaints: string | null
  history: string | null
  past_medication: string | null
  general_exam: Record<string, 'present' | 'absent'> | null
  general_exam_notes: string | null
  findings: string | null
  diagnosis: string | null
  /** Coded diagnoses from the ICD-10 list. */
  diagnosis_codes?: { code: string; name: string }[] | null
  assessment_plan: string | null
  refer_to: string | null
  tests_advised: string | null
  advice: string | null
  follow_up_on: string | null
  completed_at: string | null
  medicines: PrescribedMedicine[]
  doctor?: { id: number; name: string }
  /** Dated corrections added after the visit was locked. */
  amendments?: { id: number; body: string; created_at: string; author?: { id: number; name: string } | null }[]
}

export type VisitPatient = Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile' | 'blood_group'> & { allergies: string | null }

/** A doctor's own prescription choices. */
export type RxSettings = {
  frequencies: string[]
  timings: string[]
  print_language: 'en' | 'both' | 'local'
  translations: { phrase: string; translation: string }[]
  capitalize_name: boolean
  print: RxPrint
}

/** How a doctor's prescription is laid out on paper. */
export type RxPrint = {
  letterhead: boolean
  margin_top_mm: number
  margin_bottom_mm: number
  margin_side_mm: number
  font_size_pt: number
  show_mobile: boolean
  show_patient_id: boolean
  sections: Record<string, boolean>
  columns: Record<string, boolean>
}

export type Visit = {
  /** How the visit's doctor wants prescriptions printed. */
  rx_print?: Pick<RxSettings, 'print_language' | 'translations' | 'capitalize_name' | 'print'>
  appointment: { id: number; scheduled_at: string; status: AppointmentStatus; awaiting_reports?: boolean; notes: string | null }
  patient: VisitPatient
  doctor: { id: number; name: string; department: string | null; signature_text: string | null }
  service: { id: number; name: string } | null
  consultation: Consultation | null
  /** True from the day after the visit was finished: notes are fixed, corrections go in as amendments. */
  locked?: boolean
}

export type QueueEntry = {
  id: number
  scheduled_at: string
  /** A free follow-up of an earlier consultation with this doctor. */
  is_follow_up?: boolean
  arrived_at: string | null
  status: AppointmentStatus
  patient: VisitPatient
  doctor: { id: number; name: string }
  service: { id: number; name: string } | null
  consultation: { id: number; completed_at: string | null } | null
  token_no: number | null
  vitals_ready: boolean
  /** The doctor saved the visit to wait for lab reports; the patient is away at the lab. */
  awaiting_reports?: boolean
  /** For a visit waiting on the lab: whether its lab orders are all reported. Null when not waiting or none ordered. */
  reports?: 'pending' | 'ready' | null
  visits: number
  last_visit: string | null
}

/** A normal range for some patients only: one sex, an age band counted in completed years, months or days, or both. */
export type LabRange = { sex: 'male' | 'female' | null; age_from: number | null; age_to: number | null; age_unit: 'years' | 'months' | 'days'; range: string }

export type LabParameter = {
  id?: number
  name: string
  is_heading?: boolean
  unit: string | null
  reference_range: string | null
  /** Extra normal ranges by age and sex. The first that fits the patient is printed; otherwise `reference_range`. */
  ranges?: LabRange[] | null
  method: string | null
}

export type LabCatalogueTest = {
  id: number
  code: string | null
  name: string
  kind: 'values' | 'text'
  default_comment: string | null
  department: string | null
  sample_type: string | null
  price: number
  print_order: number
  is_active: boolean
  /** Which version of its report template is in use now. An edit to the fields makes the next one. */
  version: number
  /** Set when the test is sent to another lab: that lab's name, and what it charges the hospital. */
  outside_lab?: string | null
  outside_cost?: number | null
  parameters: LabParameter[]
}

export type LabResult = {
  id: number
  parameter: string
  is_heading: boolean
  unit: string | null
  reference_range: string | null
  method: string | null
  value: string | null
  flag: 'high' | 'low' | null
  /** The patient's last reported value for the same test, when there is one. */
  previous?: { value: string; flag: 'high' | 'low' | null; on: string } | null
}

export type LabOrderItem = {
  id: number
  test_name: string
  /** Which time this is, when the same test is on the order more than once; null when it is there once. */
  repeat_no?: number | null
  /** The version of the test's template this report was made with; null for a test made before versions and not linked to a template. */
  template_version?: number | null
  department: string | null
  sample_type: string | null
  price: number
  remarks: string | null
  kind: 'values' | 'text'
  package_name: string | null
  report_text: string | null
  stage: LabStage
  collected_at: string | null
  reported_at: string | null
  approved_at: string | null
  /** The outside lab this test is done at, when it is not done here; what that lab charges; when the sample was sent. */
  outside_lab?: string | null
  outside_cost?: number | null
  sent_at?: string | null
  reporter?: { id: number; name: string; signature_text: string | null } | null
  approver?: { id: number; name: string; signature_text: string | null } | null
  results?: LabResult[]
}

export type LabStage = 'booked' | 'collected' | 'reported' | 'approved'

export type LabPackage = { id: number; name: string; price: number; is_active: boolean; tests: { id: number; name: string; price: number }[] }

export type LabSettings = {
  lab_name: string | null
  lab_address: string | null
  technician_1: string | null
  technician_2: string | null
  letterhead_top_mm: number
  letterhead_bottom_mm: number
  each_test_on_new_page: boolean
  allow_print_without_payment: boolean
  print_sample_date: boolean
  print_approved_date: boolean
  approval_required: boolean
  /** One table for the whole report instead of a section per test. */
  tabular_print: boolean
  /** The lab's sections, in the order they are printed. Each test is filed under one. */
  departments: string[]
  /** A heading for each section on the printed report. */
  group_by_department: boolean
  /** Each section starts on a fresh page. */
  department_on_new_page: boolean
  /** The method under each parameter, where one is set. */
  print_method: boolean
  /** "End of report" after the last result. */
  print_end_mark: boolean
  /** Which version of its template each test was reported on, beside the test's name. */
  print_template_version: boolean
  /** "Processed at …" under a test that was done at an outside lab. */
  print_outside_lab: boolean
  /** The sticker for a sample tube: its size, and whether the order number is also printed as a barcode. */
  label_width_mm: number
  label_height_mm: number
  label_barcode: boolean
  /** A line of the lab's own at the foot of every report. */
  report_note: string
  /** Signature pictures, sent as embedded images; null when none is uploaded. */
  technician_1_signature: string | null
  technician_2_signature: string | null
}

export type LabOrderFile = { id: number; lab_order_item_id: number | null; title: string; original_name: string; mime: string; size: number; created_at: string; uploader?: { id: number; name: string } | null }

export type LabOrder = {
  id: number
  order_no: string
  status: 'pending' | 'partial' | 'completed'
  ordered_at: string
  referred_by: string | null
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  items: LabOrderItem[]
  files?: LabOrderFile[]
  bill: { id: number; bill_no: string; total: number; paid: number; due: number; moved_to_ipd?: number | null } | null
}

export type Medicine = {
  id: number
  name: string
  /** Where it is kept in the pharmacy: its usual rack, and the box on that rack. */
  rack_no?: string | null
  box_no?: string | null
  /** What it contains, with strengths. Brands with the same composition are options for one another. */
  composition?: string | null
  /** Its codes on the national drug list (Common Drug Codes for India), once staff have linked it. */
  ref_brand_code?: string | null
  ref_generic_code?: string | null
  type: string | null
  manufacturer: string | null
  category: string | null
  sub_category?: string | null
  /** Inventory classes: Vital/Essential/Desirable, Scarce/Difficult/Easy, Fast/Slow/Non-moving. */
  ved?: 'V' | 'E' | 'D' | null
  sde?: 'S' | 'D' | 'E' | null
  fsn?: 'F' | 'S' | 'N' | null
  is_scheduled: boolean
  hsn_code: string | null
  gst_percent: number
  units_per_pack: number
  reorder_level: number
  desired_stock?: number | null
  preferred_stockist_id?: number | null
  is_active: boolean
  units_in_stock: number
}

export type Stockist = { id: number; name: string; phone: string | null; email?: string | null; gstin: string | null; address: string | null; lead_days?: number; credit_days?: number; invoices?: number; billed?: number; paid?: number; returned?: number; balance?: number }

export type StockBatch = {
  id: number
  /** On an invoice: what to check on this line against the last purchase and the order. */
  flags?: string[]
  medicine_id: number
  batch_no: string
  expiry: string
  units_per_pack: number
  packs: number
  free_packs: number
  pack_price: number
  pack_mrp: number
  unit_mrp: number
  discount_percent: number
  gst_percent: number
  units_received: number
  units_in_stock: number
  rack_no: string | null
  box_no?: string | null
  hsn_code?: string | null
  discount_amount?: number
  tax_on_free?: boolean
  /** What one unit really cost once free packs are counted, and the margin against MRP. Sent with an invoice. */
  unit_cost?: number
  margin_percent?: number | null
  medicine?: { id: number; name: string; reorder_level?: number; type?: string | null; composition?: string | null; manufacturer?: string | null }
  /** On the inventory list: what can still be sold of this batch's medicine over all its batches. */
  medicine_units?: number
  invoice?: { id: number; invoice_no: string } | null
}

export type PurchaseInvoice = {
  id: number
  invoice_no: string
  invoice_date: string
  due_date?: string | null
  payments?: { id: number; amount: number; mode: string; reference: string | null; paid_on: string; payer?: { name: string } | null }[]
  order?: { id: number; order_no: string } | null
  flagged?: number
  subtotal: number
  tax: number
  other_charges?: number
  cash_discount?: number
  round_off?: number
  total: number
  paid: number
  remarks: string | null
  stockist: { id: number; name: string }
  batches_count?: number
  batches?: StockBatch[]
}

export type SellableMedicine = {
  id: number
  name: string
  composition?: string | null
  type: string | null
  sellable_batches: Pick<StockBatch, 'id' | 'batch_no' | 'expiry' | 'unit_mrp' | 'gst_percent' | 'units_in_stock' | 'rack_no' | 'box_no'>[]
}

export type PharmacySaleItem = {
  id: number
  stock_batch_id?: number
  medicine_name: string
  batch_no: string
  expiry: string
  quantity: number
  returned_quantity: number
  unit_price: number
  discount_percent: number
  gst_percent: number
  amount: number
}

export type PharmacySale = {
  id: number
  bill_no: string
  customer_name: string
  customer_phone: string | null
  referred_doctor: string | null
  sold_at: string
  subtotal: number
  discount: number
  tax: number
  round_off: number
  total: number
  returned: number
  paid: number
  ipd_transfer: number
  due: number
  voided_at: string | null
  void_reason: string | null
  payments?: Payment[]
  returns?: { id: number; amount: number; refunded: number; created_at: string; lines: { medicine_name: string; quantity: number; amount: number }[] }[]
  payment_mode: string
  reference: string | null
  items_count?: number
  items?: PharmacySaleItem[]
  /** Empty for a walk-in customer. */
  patient_id?: number | null
  patient?: { id: number; uhid: string; name: string } | null
  seller?: { id: number; name: string } | null
}

export type Bed = {
  id: number
  ward: string
  bed_no: string
  daily_charge: number
  /** For a ward bed also used for day care: what a day-care stay is charged a day instead. */
  day_care_charge?: number | null
  is_active: boolean
  current_admission?: { id: number; ipd_no: string; admitted_at: string; patient: { id: number; name: string; uhid: string } } | null
}

/** The hospital's own details, printed at the top of every document. */
export type Clinic = {
  name: string
  tagline: string | null
  address: string | null
  phone: string | null
  phone_alt: string | null
  email: string | null
  footer: string | null
  /** The hospital's GST number, printed on bills. */
  gstin?: string | null
  logo: string | null
  /** The hospital's own letterhead as pictures, for the top and the foot of a printed page; embedded images, or null. */
  letterhead_header?: string | null
  letterhead_footer?: string | null
}

export type ReferralDoctor = { id: number; direction: 'by' | 'to'; name: string; specialization: string | null; phone: string | null; place: string | null }

export type PatientMerge = { id: number; from_uhid: string; from_name: string; to_uhid: string; to_name: string; moved: Record<string, number> | null; created_at: string; staff?: { id: number; name: string } | null }

export type BillingPreferences = {
  default_payment_mode: string
  pay_timing: 'before' | 'after'
  /** A visit to the same doctor within this many days of a consultation is a follow-up and is not charged. 0 is off. */
  follow_up_days?: number
  ipd_prefix: string
  ipd_bill_prefix?: string
  /** Bills go on the hospital's letterhead paper: no hospital details or logo are printed, and room is left for it. */
  print_on_letterhead?: boolean
  letterhead_top_mm?: number
  letterhead_bottom_mm?: number
  /** The heading of bills on plain paper: logo on the left with the details beside it, or everything centred. */
  bill_heading?: 'side' | 'centre' | 'image'
  bill_heading_size?: 'small' | 'medium' | 'large'
  bill_logo_size?: 'small' | 'medium' | 'large'
  /** On the printed IPD bill, each medicine is listed under the pharmacy bill it came on. */
  ipd_print_medicines?: boolean
  /** The paper receipts and small bills are printed on. A counter can choose another on the print page. */
  bill_paper_size?: 'a4' | 'a5' | 'thermal'
  /** The hospital's GSTIN under the bill number of every bill and receipt. */
  print_gstin?: boolean
  /** Letters put before the patient ID of each new patient; empty for none. */
  patient_prefix?: string | null
}

/** A row of a hospital-wide list: a department, a vendor, or a payer. */
export type AdminEntry = { id: number; kind: 'department' | 'vendor' | 'payer'; name: string; details: Record<string, string> | null; is_active: boolean }

/** One line of a hospital-designed form. */
export type FormField = { id: string; label: string; type: 'heading' | 'text' | 'textarea' | 'number' | 'date' | 'choice' | 'checkbox' | 'signature'; options: string[] | null }

export type FormTemplate = { id: number; name: string; access: 'doctor' | 'ipd' | 'both'; fields: FormField[]; is_active: boolean }

/** A filled-in form. It carries its own copy of the questions. */
export type FormRecord = {
  id: number
  name: string
  fields: FormField[]
  values: Record<string, string | boolean> | null
  created_at: string
  author?: { id: number; name: string } | null
  patient?: Pick<Patient, 'id' | 'uhid' | 'name' | 'title' | 'gender' | 'age_label'>
  admission?: { id: number; ipd_no: string; doctor?: { id: number; name: string } | null } | null
  /** Set when the form was filled in during an OPD visit. */
  appointment?: { id: number; scheduled_at: string; doctor?: { id: number; name: string } | null } | null
}

export type NoteKind = 'clinical' | 'nursing' | 'ot'

/** A clinical, nursing or operation note written during a stay. */
export type AdmissionNote = { id: number; kind: NoteKind; noted_at: string; body: string | null; details: Record<string, string> | null; author?: { id: number; name: string } | null }

/** The discharge summary of a stay. `id` is null until it is first saved. */
export type DischargeSummary = {
  id: number | null
  diagnosis: string | null
  complaints?: string | null
  history?: string | null
  examination?: string | null
  investigations?: string | null
  treatment_given?: string | null
  procedures?: string | null
  operative_findings?: string | null
  tests_requested?: string | null
  other_instructions?: string | null
  acknowledgement?: string | null
  course: string | null
  condition_at_discharge?: string | null
  medicines: PrescribedMedicine[] | null
  advice?: string | null
  follow_up_on: string | null
  approved_at: string | null
  preparer?: { id: number; name: string } | null
  approver?: { id: number; name: string; signature_text?: string | null } | null
}

export type IpdBillTemplate = { id: number; name: string; items: { ipd_service_id: number; quantity: number }[] }

export type IpdService = { id: number; name: string; category: string | null; price: number; is_active: boolean }

export type AdmissionStatus = 'admitted' | 'discharged' | 'lama' | 'dama' | 'absconded' | 'died'

export type AdmissionCharge = {
  id: number
  description: string
  category: string | null
  charged_on: string
  quantity: number
  unit_price: number
  discount: number
  amount: number
  remarks: string | null
  /** Set when the line stands for another bill moved onto this one. */
  source_type?: string | null
  /** The id of that other bill: a front desk or lab bill for "bill", a pharmacy bill for "pharmacy". */
  source_id?: number | null
  /** The numbered bill this charge is printed on, if any. */
  admission_bill_id?: number | null
  /** For a pharmacy bill on the IPD bill: the medicines that were on it. */
  medicines?: { name: string; batch: string | null; quantity: number; returned: number; rate: number; amount: number }[]
  creator?: { id: number; name: string } | null
  deleted_at?: string | null
  deleter?: { id: number; name: string } | null
}

/** A patient admitted now, as the pharmacy counter lists them to send a bill to the IPD bill. */
export type PharmacyInpatient = { admission_id: number; ipd_no: string; patient: Patient; bed: string | null; doctor: string | null; bill_approved: boolean }

/** One bed a patient occupied during a stay. `to_at` is null while they are still in it. */
export type BedStay = { id: number; ward: string; bed_no: string; from_at: string; to_at: string | null; mover?: { id: number; name: string } | null }

export type Admission = {
  id: number
  ipd_no: string
  /** Every bed of the stay, in order. */
  bed_stays?: BedStay[]
  /** Whether the app adds this patient's bed charge each day (when the hospital has that switched on at all). */
  auto_bed_charge?: boolean
  /** A day-care stay: its bed charges use the bed's day-care rate, where it has one. */
  is_day_care?: boolean
  /** Whether the hospital has automatic bed charges switched on. */
  bed_charges_auto?: boolean
  status: AdmissionStatus
  admitted_at: string
  discharged_at: string | null
  reason: string | null
  attendant_name: string | null
  attendant_phone: string | null
  discharge_summary: string | null
  bill_approved_at: string | null
  bill_approver?: { id: number; name: string } | null
  /** Who settles the bill when it is not the patient. */
  payer?: { id: number; name: string } | null
  total: number
  discount: number
  net: number
  paid: number
  due: number
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile' | 'address' | 'city' | 'blood_group'>
  doctor: { id: number; name: string; department?: string | null; signature_text?: string | null }
  bed: { id: number; ward: string; bed_no: string; daily_charge?: number; day_care_charge?: number | null } | null
  charges?: AdmissionCharge[]
  /** Numbered bills made from the charges, oldest first. */
  bills?: { id: number; bill_no: string; billed_on: string; notes: string | null; total: number; creator?: { id: number; name: string } | null }[]
  /** Lines taken off the bill, kept for the record. */
  deleted_charges?: AdmissionCharge[]
  aadhaar_no?: string | null
  gst_no?: string | null
  payments?: (Omit<Payment, 'type'> & { type: 'advance' | 'payment' | 'refund'; remarks?: string | null })[]
}

export type PrescriptionForPharmacy = {
  id: number
  completed_at: string
  doctor: string | null
  medicines: { medicine: string; directions: string; match: SellableMedicine | null }[]
}

export type OutstandingBill = { type: 'bill' | 'pharmacy'; id: number; label: string; category: string; date: string; due: number }

export type Manufacturer = { id: number; name: string }

export type PharmacyDashboard = {
  from: string
  to: string
  sales: number
  billed: number
  collected: number
  collected_cash: number
  collected_card: number
  collected_other: number
  /** Money collected in the period by each way of paying: cash, upi, card, cheque, bank_transfer, insurance. */
  collected_by_mode: Record<string, number>
  statistics: { medicines: number; manufacturers: number; inventory_cost: number; inventory_mrp: number; units_in_stock: number; out_of_stock: number }
  latest_sales: PharmacySale[]
  fast_moving: { medicine_name: string; sold: number }[]
}

export type PrescriptionOrder = {
  id: number
  completed_at: string
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  doctor: string | null
  medicines: { id: number; medicine: string; dose: string | null; frequency: string | null; duration: string | null }[]
  bill: { id: number; bill_no: string } | null
}

export type InventoryMedicine = {
  id: number
  name: string
  composition?: string | null
  type: string | null
  manufacturer: string | null
  reorder_level: number
  units_in_stock: number
  expiring_units: number
  next_expiry: string | null
}

export type StockReturn = {
  id: number
  return_no: string
  returned_on: string
  total: number
  remarks: string | null
  stockist: { id: number; name: string }
  items: { id: number; medicine_name: string; batch_no: string; quantity: number; unit_cost: number; amount: number }[]
}

export type PurchaseOrder = {
  id: number
  order_no: string
  order_date: string
  status: 'draft' | 'open' | 'received' | 'cancelled'
  expected_on?: string | null
  approved_at?: string | null
  remarks: string | null
  stockist: { id: number; name: string; phone?: string | null; address?: string | null }
  items_count?: number
  items?: { id: number; medicine_name: string; units_per_pack: number; packs: number }[]
  match?: {
    lines: { medicine_id: number; medicine_name: string; ordered: number; received: number; free: number; state: 'none' | 'short' | 'full' | 'over' }[]
    extras: { medicine_name: string; received: number; free: number }[]
    invoices: { id: number; invoice_no: string; invoice_date: string; total: number }[]
    complete: boolean
  }
}

export type ReportColumn = { key: string; label: string; type: 'text' | 'money' | 'number'; total: boolean }
export type PharmacyReport = {
  type: string
  title: string
  from: string
  to: string
  columns: ReportColumn[]
  rows: Record<string, string | number | null>[]
  totals: Record<string, number>
  reports: Record<string, string>
  /** On reports about bills and payments: the money that counter received in the period, by how it was paid. */
  received?: Record<string, number>
}

export type WalkInBill = {
  id: number
  bill_no: string
  kind: BillKind
  total: number
  paid: number
  due: number
  moved_to_ipd: number | null
  created_at: string
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  lab_order: { id: number; order_no: string; items: { id: number }[] } | null
  items: { id: number; description: string }[]
}

type DeptTotals = { billed: number; collected: number; refunded: number; orders: number; outstanding: number }
export type DaySummary = {
  date: string
  bills: Record<'all' | 'appointment' | 'lab' | 'other', DeptTotals>
  /** All the money that came in at the desk that day, on bills and as deposits, by how it was paid. */
  received: Record<string, number>
  appointments: { doctor: string; total: number; booked: number; arrived: number; ongoing: number; reviewed: number }[]
}

export type Attachment = {
  id: number
  title: string
  document_date: string
  original_name: string
  mime: string
  size: number
  uploader: { id: number; name: string } | null
}

export type QueueBoard = { doctor: string; department: string | null; now: { token: number; name: string }[]; waiting: { token: number; name: string }[] }[]

/** One movement of a patient's deposit. */
export type PatientDeposit = { id: number; type: 'deposit' | 'used' | 'refund'; amount: number; mode: string | null; reference: string | null; created_at: string; receiver?: { id: number; name: string } | null }

export type PatientOverview = {
  patient: Patient & { allergies: string | null }
  outstanding: number
  deposit_balance: number
  deposits: PatientDeposit[]
  visits: { id: number; scheduled_at: string; status: AppointmentStatus; doctor: { name: string }; service: { name: string } | null; consultation: { id: number; diagnosis: string | null; completed_at: string | null } | null }[]
  bills: { id: number; bill_no: string; kind: BillKind; total: number; paid: number; due: number; moved_to_ipd: number | null; created_at: string; cancelled_at?: string | null; lab_order: { order_no: string } | null }[]
  lab_orders: { id: number; order_no: string; status: string; ordered_at: string; items: { id: number; test_name: string; repeat_no?: number | null }[] }[]
  pharmacy_bills: { id: number; bill_no: string; sold_at: string; total: number; due: number; ipd_transfer: number }[]
  admissions: { id: number; ipd_no: string; status: string; admitted_at: string; discharged_at: string | null; due: number; doctor: { name: string }; bed: { ward: string; bed_no: string } | null }[]
  attachments: Attachment[]
}

export type PhraseKind = 'complaint' | 'diagnosis' | 'advice'
export type RememberedPhrase = { id: number; kind: PhraseKind; text: string; uses: number; last_used_at: string | null }
export type RxGroup = { id: number; name: string; medicines: PrescribedMedicine[] }
export type TemplateContent = {
  complaints?: string | null
  findings?: string | null
  diagnosis?: string | null
  assessment_plan?: string | null
  tests_advised?: string | null
  advice?: string | null
  medicines?: PrescribedMedicine[] | null
}
export type PrescriptionTemplate = { id: number; name: string; content: TemplateContent }
export type DoctorLibrary = {
  doctor_id: number
  // The server sends an empty list instead of an object when nothing is remembered yet.
  phrases: Partial<Record<PhraseKind, RememberedPhrase[]>> | []
  rx_groups: RxGroup[]
  templates: PrescriptionTemplate[]
  medicine_defaults: PrescribedMedicine[]
  rx_settings: RxSettings
  has_seal: boolean
  pad_hidden_sections: string[]
  pad_sections: string[]
  /** Every movable part of the pad, in this doctor's order. */
  pad_section_order: string[]
}
export type ConsultRow = {
  id: number
  appointment_id: number
  diagnosis: string | null
  follow_up_on: string | null
  completed_at: string
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'age_label' | 'title' | 'mobile'>
  doctor: { id: number; name: string }
}
