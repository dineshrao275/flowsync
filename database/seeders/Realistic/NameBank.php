<?php

namespace Database\Seeders\Realistic;

/** Static vocabularies for the realistic demo dataset. Pure data: no randomness, no database. */
final class NameBank
{
    public const PREFIXES = [
        'Aldervale', 'Brightwater', 'Cedarpoint', 'Driftwood', 'Everline', 'Fernhill', 'Granite Peak', 'Harborlight',
        'Ironwood', 'Juniper Row', 'Kestrel', 'Lakeshore', 'Maplecrest', 'Novacraft', 'Oakridge', 'Pinnacle Bay',
        'Quillon', 'Riverbend', 'Silverline', 'Thornbury', 'Umberfield', 'Valemont', 'Westbrook', 'Yarrow',
        'Zephyr Ridge', 'Amberly', 'Bluefin', 'Copperleaf', 'Dunmore', 'Elmstead', 'Foxglove', 'Glenmar',
        'Hollis', 'Ivywood', 'Jadeport', 'Kingsfield', 'Larkspur', 'Montclair', 'Nettlefield', 'Orchard Lane',
        'Pembroke', 'Quartzwood', 'Redcliff', 'Stonebridge', 'Tidewell', 'Upland', 'Vantage Row', 'Whitmore',
        'Xenith', 'Yorkstead', 'Zenbrook', 'Ashgrove', 'Birchwell', 'Crestline', 'Dovetail', 'Eastmere',
        'Fairhaven', 'Greystone', 'Highmoor', 'Inkwell',
    ];

    /** industry => [label, legal-form suffixes, department set key, product focus words] */
    public const INDUSTRIES = [
        'logistics' => ['Logistics', ['Ltd', 'Pvt Ltd', 'LLP'], 'ops'],
        'healthcare' => ['Healthcare', ['Inc', 'Ltd', 'Group'], 'clinical'],
        'fintech' => ['Financial Services', ['Ltd', 'Inc', 'Capital'], 'finance'],
        'software' => ['Software', ['Labs', 'Technologies', 'Systems'], 'tech'],
        'manufacturing' => ['Industries', ['Ltd', 'Pvt Ltd', 'Works'], 'ops'],
        'retail' => ['Retail', ['Stores', 'Ltd', 'Co'], 'commerce'],
        'education' => ['Learning', ['Academy', 'Institute', 'Ltd'], 'campus'],
        'media' => ['Media', ['Studios', 'Collective', 'Ltd'], 'creative'],
        'construction' => ['Constructions', ['Ltd', 'Builders', 'Group'], 'ops'],
        'energy' => ['Energy', ['Ltd', 'Power', 'Group'], 'ops'],
        'hospitality' => ['Hospitality', ['Group', 'Resorts', 'Ltd'], 'commerce'],
        'biotech' => ['Biosciences', ['Inc', 'Labs', 'Ltd'], 'clinical'],
        'telecom' => ['Networks', ['Ltd', 'Communications', 'Inc'], 'tech'],
        'agritech' => ['AgriTech', ['Ltd', 'Farms', 'Cooperative'], 'ops'],
        'consulting' => ['Advisory', ['Partners', 'LLP', 'Consulting'], 'finance'],
    ];

    /** department set => [[name, code, designations...]] */
    public const DEPARTMENTS = [
        'tech' => [
            ['Engineering', 'ENG'], ['Product Management', 'PRD'], ['Design', 'DSN'], ['Quality Assurance', 'QA'],
            ['DevOps & Infrastructure', 'OPS'], ['Customer Success', 'CSM'], ['Sales', 'SAL'], ['Marketing', 'MKT'],
            ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'],
        ],
        'ops' => [
            ['Operations', 'OPS'], ['Supply Chain', 'SCM'], ['Quality & Compliance', 'QAC'], ['Procurement', 'PRC'],
            ['Engineering', 'ENG'], ['Sales', 'SAL'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'],
            ['Health & Safety', 'HSE'], ['IT Services', 'ITS'],
        ],
        'clinical' => [
            ['Clinical Services', 'CLN'], ['Research & Development', 'RND'], ['Regulatory Affairs', 'REG'], ['Patient Care', 'PTC'],
            ['Laboratory', 'LAB'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'], ['IT Services', 'ITS'],
            ['Business Development', 'BDV'], ['Quality Assurance', 'QA'],
        ],
        'finance' => [
            ['Advisory', 'ADV'], ['Risk & Compliance', 'RSK'], ['Client Services', 'CLS'], ['Analytics', 'ANL'],
            ['Technology', 'TEC'], ['Operations', 'OPS'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'],
            ['Business Development', 'BDV'], ['Legal', 'LGL'],
        ],
        'commerce' => [
            ['Store Operations', 'STO'], ['Merchandising', 'MER'], ['E-commerce', 'ECM'], ['Customer Care', 'CCR'],
            ['Marketing', 'MKT'], ['Supply Chain', 'SCM'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'],
            ['IT Services', 'ITS'], ['Sales', 'SAL'],
        ],
        'campus' => [
            ['Academics', 'ACD'], ['Curriculum Design', 'CUR'], ['Student Services', 'STU'], ['Admissions', 'ADM'],
            ['Technology', 'TEC'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'], ['Marketing', 'MKT'],
            ['Facilities', 'FAC'], ['Research', 'RSH'],
        ],
        'creative' => [
            ['Production', 'PRO'], ['Editorial', 'EDT'], ['Design', 'DSN'], ['Audience Growth', 'AUD'],
            ['Partnerships', 'PAR'], ['Technology', 'TEC'], ['Finance & Accounts', 'FIN'], ['People & Culture', 'HR'],
            ['Sales', 'SAL'], ['Legal & Rights', 'LGL'],
        ],
    ];

    /** Levels mirror the provisioned designations: 4 manager, 3 team lead, 2 senior, 1 specialist. */
    public const DESIGNATION_TITLES = [
        4 => ['Head of {dept}', 'Director, {dept}', '{dept} Manager'],
        3 => ['Team Lead, {dept}', 'Lead {role}'],
        2 => ['Senior {role}', 'Principal {role}'],
        1 => ['{role}', 'Associate {role}', 'Junior {role}'],
    ];

    public const ROLES_BY_DEPT_CODE = [
        'ENG' => ['Software Engineer', 'Backend Engineer', 'Frontend Engineer', 'Full-stack Engineer', 'Mobile Engineer'],
        'PRD' => ['Product Analyst', 'Product Owner', 'Business Analyst'],
        'DSN' => ['UX Designer', 'Visual Designer', 'Design Researcher'],
        'QA' => ['QA Engineer', 'Test Automation Engineer', 'Quality Analyst'],
        'OPS' => ['Operations Executive', 'Site Reliability Engineer', 'Operations Analyst'],
        'CSM' => ['Customer Success Manager', 'Support Specialist', 'Onboarding Specialist'],
        'SAL' => ['Sales Executive', 'Account Executive', 'Inside Sales Representative'],
        'MKT' => ['Marketing Executive', 'Content Strategist', 'Growth Analyst'],
        'FIN' => ['Accountant', 'Financial Analyst', 'Payables Executive'],
        'HR' => ['HR Executive', 'Talent Partner', 'People Operations Specialist'],
    ];

    public const FIRST_NAMES = [
        'Aarav', 'Aditi', 'Akash', 'Ananya', 'Arjun', 'Bhavna', 'Chetan', 'Deepa', 'Divya', 'Esha', 'Farhan', 'Gauri',
        'Harsh', 'Isha', 'Jatin', 'Kavya', 'Lakshmi', 'Manish', 'Neha', 'Omkar', 'Pooja', 'Rahul', 'Sana', 'Tarun',
        'Uma', 'Varun', 'Yash', 'Zoya', 'Amelia', 'Benjamin', 'Chloe', 'Daniel', 'Emily', 'Felix', 'Grace', 'Henry',
        'Isabel', 'Jack', 'Katherine', 'Liam', 'Maya', 'Noah', 'Olivia', 'Peter', 'Quinn', 'Rachel', 'Samuel', 'Tessa',
        'Victor', 'Willow', 'Adrian', 'Beatriz', 'Carlos', 'Daniela', 'Mateo', 'Sofia', 'Yusuf', 'Leila', 'Omar', 'Nadia',
        'Kenji', 'Mei', 'Hiroshi', 'Aiko', 'Chen', 'Linh', 'Nguyen', 'Priya', 'Rohan', 'Sneha', 'Vikram', 'Meera',
        'Elena', 'Marcus', 'Ingrid', 'Lars', 'Anika', 'Tomas', 'Zara', 'Ibrahim', 'Fatima', 'Kofi', 'Amara', 'Tunde',
    ];

    public const LAST_NAMES = [
        'Sharma', 'Verma', 'Iyer', 'Nair', 'Reddy', 'Patel', 'Mehta', 'Kapoor', 'Chatterjee', 'Banerjee', 'Gupta', 'Joshi',
        'Menon', 'Rao', 'Desai', 'Bhatt', 'Khan', 'Siddiqui', 'Malhotra', 'Singh', 'Anderson', 'Brooks', 'Carter', 'Davies',
        'Edwards', 'Foster', 'Garcia', 'Hughes', 'Ibrahim', 'Jensen', 'Kowalski', 'Larsen', 'Martinez', 'Novak', 'Okafor',
        'Petrov', 'Quintero', 'Rossi', 'Schneider', 'Tanaka', 'Ueda', 'Vasquez', 'Williams', 'Yamamoto', 'Zhang', 'Alvarez',
        'Bianchi', 'Costa', 'Dubois', 'Eriksson', 'Fischer', 'Gonzalez', 'Haddad', 'Ito', 'Johansson', 'Kim', 'Lopez',
        'Moreau', 'Nakamura', 'Oliveira', 'Pereira', 'Rahman', 'Santos', 'Tran', 'Usman', 'Varga', 'Wagner', 'Xu', 'Yilmaz',
    ];

    public const COUNTRIES = [
        ['IN', 'India', 'Asia/Kolkata', 'INR', 'Maharashtra', 'Mumbai'], ['IN', 'India', 'Asia/Kolkata', 'INR', 'Karnataka', 'Bengaluru'],
        ['IN', 'India', 'Asia/Kolkata', 'INR', 'Telangana', 'Hyderabad'], ['US', 'United States', 'America/New_York', 'USD', 'New York', 'New York'],
        ['US', 'United States', 'America/Chicago', 'USD', 'Texas', 'Austin'], ['GB', 'United Kingdom', 'Europe/London', 'GBP', 'England', 'Manchester'],
        ['DE', 'Germany', 'Europe/Berlin', 'EUR', 'Berlin', 'Berlin'], ['AE', 'United Arab Emirates', 'Asia/Dubai', 'AED', 'Dubai', 'Dubai'],
        ['SG', 'Singapore', 'Asia/Singapore', 'SGD', 'Singapore', 'Singapore'], ['AU', 'Australia', 'Australia/Sydney', 'AUD', 'NSW', 'Sydney'],
        ['CA', 'Canada', 'America/Toronto', 'CAD', 'Ontario', 'Toronto'], ['ES', 'Spain', 'Europe/Madrid', 'EUR', 'Madrid', 'Madrid'],
    ];

    /** Product areas a project is named after. */
    public const PROJECT_AREAS = [
        'Customer Portal', 'Mobile App', 'Billing Engine', 'Analytics Dashboard', 'Partner API', 'Onboarding Flow',
        'Inventory Sync', 'Notification Service', 'Search Revamp', 'Payments Gateway', 'Reporting Suite', 'Identity & Access',
        'Data Warehouse', 'Support Desk', 'Order Tracking', 'Scheduling Engine', 'Document Vault', 'Admin Console',
        'Loyalty Programme', 'Fraud Detection', 'Content Hub', 'Procurement Workflow', 'Compliance Tracker', 'Field Operations',
        'Marketplace', 'Knowledge Base', 'Workforce Planner', 'Integration Hub', 'Quality Audits', 'Demand Forecasting',
        'Customer Insights', 'Vendor Management', 'Self-Service Portal', 'Asset Registry', 'Release Automation', 'Security Hardening',
    ];

    public const PROJECT_PHASES = ['Phase 1', 'Phase 2', 'Revamp', 'Migration', 'Launch', 'Rollout', 'Stabilisation', 'Expansion', 'Pilot', 'v2'];

    public const TASK_VERBS = ['Fix', 'Implement', 'Design', 'Review', 'Migrate', 'Document', 'Test', 'Optimise', 'Investigate', 'Automate', 'Refactor', 'Prepare', 'Update', 'Audit', 'Add', 'Replace', 'Validate', 'Monitor'];

    public const TASK_OBJECTS = [
        'login validation', 'CSV export', 'dashboard widgets', 'notification templates', 'role permissions', 'search indexing',
        'invoice generation', 'user onboarding checklist', 'API rate limits', 'audit log retention', 'report scheduler', 'webhook retries',
        'file upload limits', 'mobile navigation', 'session timeout', 'email digest', 'data import wizard', 'two-factor setup',
        'order status sync', 'pricing rules', 'tax calculation', 'customer filters', 'bulk actions', 'dark theme', 'accessibility labels',
        'pagination performance', 'cache invalidation', 'queue workers', 'backup verification', 'release checklist', 'vendor contracts',
        'approval routing', 'calendar integration', 'export permissions', 'timezone handling', 'translation catalogue', 'empty states',
        'error monitoring', 'database indexes', 'permission matrix', 'staging environment', 'load test scenarios', 'onboarding emails',
    ];

    public const TASK_CONTEXTS = [
        'for the %s team', 'ahead of the quarterly review', 'after customer feedback', 'to unblock the launch', 'for compliance sign-off',
        'before the next release', 'on mobile', 'for enterprise accounts', 'in the admin console', 'across all regions', 'in staging', '',
        '', '', '',
    ];

    public const LABELS = [
        ['frontend', '#3b82f6'], ['backend', '#10b981'], ['ux', '#a855f7'], ['security', '#ef4444'], ['performance', '#f59e0b'],
        ['tech-debt', '#64748b'], ['customer-request', '#ec4899'], ['compliance', '#0ea5e9'],
    ];

    public const COMMENTS = [
        'Picked this up; will share an update by end of day.', 'Blocked on the design review — pinged the team.',
        'Reproduced on staging. Root cause looks like a stale cache entry.', 'PR is up, please review when you get a chance.',
        'Moved the estimate up after looking at the integration points.', 'Confirmed with the customer; they are fine with the revised scope.',
        'Added tests for the edge cases mentioned above.', 'This overlaps with the work in the other ticket — linking them.',
        'Deployed to staging, QA can verify now.', 'Looks good to me. Merging after the pipeline passes.',
        'Needs a quick call to agree the acceptance criteria.', 'Rolled back the change; investigating the regression.',
        'Documentation updated in the wiki.', 'Re-prioritising this given the upcoming deadline.', 'Verified in production, closing this out.',
    ];

    public const ASSET_CATALOGUE = [
        ['Laptops', 'LAP', [['Dell', 'Latitude 5440', 92000], ['Lenovo', 'ThinkPad T14', 88000], ['Apple', 'MacBook Pro 14', 185000]]],
        ['Monitors', 'MON', [['Dell', 'U2723QE', 42000], ['LG', '27UP850', 36000]]],
        ['Mobile Phones', 'MOB', [['Samsung', 'Galaxy S23', 62000], ['Apple', 'iPhone 14', 75000]]],
        ['Access Cards', 'ACC', [['HID', 'iClass SE', 900]]],
        ['Furniture', 'FUR', [['Herman Miller', 'Aeron Chair', 78000], ['IKEA', 'Bekant Desk', 18000]]],
    ];

    public const SKILLS = [
        ['PHP', 'Engineering'], ['JavaScript', 'Engineering'], ['SQL', 'Engineering'], ['Cloud Infrastructure', 'Engineering'],
        ['Product Discovery', 'Product'], ['Stakeholder Management', 'Leadership'], ['People Management', 'Leadership'],
        ['Financial Modelling', 'Finance'], ['Customer Communication', 'Customer'], ['Data Analysis', 'Analytics'],
    ];
}
