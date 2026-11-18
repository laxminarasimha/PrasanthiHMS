<?php

namespace App\Support;

/**
 * The sections of a laboratory (haematology, biochemistry and so on), and a first guess at which one a test belongs
 * to from its name. The guess is only ever offered for a person to check; nothing is filed under a department unasked.
 */
class LabDepartments
{
    /** The usual sections, in the order they are printed on a report. The hospital can change the list. */
    public const STANDARD = [
        'Haematology', 'Biochemistry', 'Clinical Pathology', 'Serology and Immunology', 'Hormones',
        'Microbiology', 'Histopathology and Cytology', 'Radiology and Imaging', 'Cardiology',
    ];

    /**
     * Words in a test's name that point to a section, most telling first: "urine culture" is microbiology before it is
     * clinical pathology, and "glycosylated haemoglobin" is biochemistry before it is haematology.
     */
    private const SIGNS = [
        ['Microbiology', '/\b(culture|sensitivity|c\s*\/\s*s|gram\s*stain|afb|koh|zn\s*stain)\b/i'],
        ['Histopathology and Cytology', '/\b(biopsy|histopath\w*|fnac|cytology|pap\s*smear)\b/i'],
        ['Radiology and Imaging', '/\b(x[\s-]?ray|ultrasound|usg|sonograph\w*|scan|mri|doppler|mammogra\w*)\b/i'],
        ['Cardiology', '/\b(ecg|ekg|echo\w*|tmt|treadmill|holter)\b/i'],
        ['Biochemistry', '/\b(hba1c|glycosylated|glycated)\b/i'],
        ['Clinical Pathology', '/\b(urine|urinary|stool|semen|sputum|csf|body\s*fluid|pleural|ascitic|synovial|pregnancy\s*test|upt|occult\s*blood)\b/i'],
        ['Hormones', '/\b(tsh|t3|t4|ft3|ft4|thyroid|prolactin|fsh|lh|testosterone|cortisol|insulin|hcg|amh|estradiol|oestradiol|oestrogen|estrogen|progesterone|pth|parathyroid|hormone|luteinizing|dheas?|acth|adh|c[\s-]*peptide|igf\w*|aldosterone|androstenedione|calcitonin|catecholamines?|metanephrines?|adrenaline|ketosteroids?|corticosteroids?|renin)\b/i'],
        ['Serology and Immunology', '/\b(hiv|hbsag|hbs\s*ag|hcv|hepatitis|vdrl|rpr|widal|dengue|ns1|typhi\w*|aso|ra\s*factor|rheumatoid|crp|c[\s-]*reactive|antigen|antibod\w*|igg|igm|ige|iga|elisa|rapid|covid|chikungunya|scrub|leptospira|ana|torch|tpha|anti|ab\s*to|hla|ccp|ttg|weil|brucella|serology|complement|ca[\s-]*\d+(\.\d+)?|afp|cea|psa)\b/i'],
        ['Haematology', '/\b(ha?emoglobin|hb|cbp|cbc|complete\s*blood|blood\s*picture|ha?emogram|esr|platelet\w*|wbc|tlc|dlc|differential|pcv|ha?ematocrit|reticulocyte\w*|peripheral\s*smear|blood\s*group\w*|bleeding\s*time|clotting\s*time|bt\s*ct|prothrombin|inr|aptt|d[\s-]*dimer|coagulation|malaria\w*|mp|sickling|coombs|aec|eosinophil\w*|rbc|red\s*cell|neutrophil\w*|lymphocyte\w*|basophil\w*|monocyte\w*|granulocyte\w*|mcv|mch|mchc|rdw|thromboplastin|ptt|fibrinogen|cd[348]|microfilaria|g6pd|bone\s*marrow)\b/i'],
        ['Biochemistry', '/\b(glucose|sugar|fbs|ppbs|rbs|gtt|urea|bun|creatinine|uric\s*acid|bilirubin|sgot|sgpt|ast|alt|alkaline|alp|ggt|protein\w*|albumin|globulin|lft|liver|rft|kft|renal|kidney|lipid|cholesterol|triglyceride\w*|hdl|ldl|vldl|electrolyte\w*|sodium|potassium|chloride|calcium|phosph\w*|magnesium|amylase|lipase|ldh|cpk|ck[\s-]*mb|troponin|iron|ferritin|tibc|vitamin|b12|folate|ammonia|lactate|bicarbonate|zinc|copper|lithium|osmolal\w*|osmolar\w*|homocysteine|lipoprotein|apolipoprotein|ketone|acetone|bnp|cystatin|cholinesterase|adenosine|enzyme|digoxin|carbamazepine|cyclosporine|phenytoin|valpro\w*)\b/i'],
    ];

    /** The section a test with this name most likely belongs to, if its name says anything at all. */
    public static function guess(string $testName): ?string
    {
        foreach (self::SIGNS as [$department, $pattern]) {
            if (preg_match($pattern, $testName)) {
                return $department;
            }
        }

        return null;
    }
}
