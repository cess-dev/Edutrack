-- Fix school_knowledge seed answers — remove placeholder text
-- Run: mysql -u root edutrack_db < fix_school_knowledge.sql

UPDATE school_knowledge
SET answer = 'Fee deadline information has not been added to the system yet. Please contact the school admin office directly for the exact amount and due date.'
WHERE category = 'fees';

UPDATE school_knowledge
SET answer = 'The term calendar has not been added to the system yet. Please contact the school admin office or check the notice board for holiday dates.'
WHERE category = 'holidays';

UPDATE school_knowledge
SET answer = 'Exam timetables have not been added to the system yet. Please check the school notice board or contact the admin office.'
WHERE category = 'exams';

UPDATE school_knowledge
SET answer = 'Students are required to wear full school uniform on all school days. Contact the admin office for the detailed uniform policy.'
WHERE category = 'uniform';
