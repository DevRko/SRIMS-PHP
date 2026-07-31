-- ============================================================
-- SRIMS - Stationery Requisition & Inventory Management System
-- Database schema + seed data (converted from Prisma schema)
-- ============================================================

CREATE DATABASE IF NOT EXISTS srims;
USE srims;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


CREATE TABLE IF NOT EXISTS `departments` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('ADMIN','USER','APPROVER','INVENTORY_MGR') NOT NULL DEFAULT 'USER',
  `department_id` VARCHAR(40) NOT NULL,
  `approver_id` VARCHAR(40) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `avatar_url` LONGTEXT DEFAULT NULL,
  KEY `idx_users_department` (`department_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_users_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `user_id` VARCHAR(40) NOT NULL,
  `token` VARCHAR(191) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prt_token` (`token`),
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL UNIQUE,
  `parent_id` VARCHAR(40) DEFAULT NULL,
  `icon` VARCHAR(60) DEFAULT NULL,
  `color` VARCHAR(20) DEFAULT NULL,
  `bg_color` VARCHAR(20) DEFAULT NULL,
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `items` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `category_id` VARCHAR(40) NOT NULL,
  `unit` VARCHAR(40) NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `current_stock` INT NOT NULL DEFAULT 0,
  `min_stock_level` INT NOT NULL DEFAULT 10,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `icon_key` VARCHAR(191) DEFAULT NULL,
  KEY `idx_items_category` (`category_id`),
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `contact` VARCHAR(100) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `requisitions` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `user_id` VARCHAR(40) NOT NULL,
  `department_id` VARCHAR(40) NOT NULL,
  `status` ENUM('DRAFT','PENDING','APPROVED','REJECTED','ISSUED','PARTIAL') NOT NULL DEFAULT 'DRAFT',
  `purpose` VARCHAR(191) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `required_date` DATE DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `priority` ENUM('LOW','NORMAL','URGENT') NOT NULL DEFAULT 'NORMAL',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_by_id` VARCHAR(40) DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_reason` TEXT DEFAULT NULL,
  KEY `idx_req_status` (`status`),
  KEY `idx_req_user` (`user_id`),
  KEY `idx_req_department` (`department_id`),
  KEY `idx_req_created` (`created_at`),
  CONSTRAINT `fk_req_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_req_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_req_approver` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `requisition_items` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `requisition_id` VARCHAR(40) NOT NULL,
  `item_id` VARCHAR(40) NOT NULL,
  `requested_qty` INT NOT NULL,
  `approved_qty` INT NOT NULL DEFAULT 0,
  `issued_qty` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(12,2) NOT NULL,
  CONSTRAINT `fk_ri_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `issuances` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `requisition_id` VARCHAR(40) NOT NULL,
  `issued_by_id` VARCHAR(40) NOT NULL,
  `issued_to_id` VARCHAR(40) NOT NULL,
  `received_by` VARCHAR(191) DEFAULT NULL,
  `issue_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_no` VARCHAR(60) NOT NULL UNIQUE,
  `remarks` TEXT DEFAULT NULL,
  CONSTRAINT `fk_iss_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`),
  CONSTRAINT `fk_iss_issuedby` FOREIGN KEY (`issued_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_iss_issuedto` FOREIGN KEY (`issued_to_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `type` ENUM('INWARD','OUTWARD','ADJUSTMENT') NOT NULL,
  `item_id` VARCHAR(40) NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `reference_no` VARCHAR(60) DEFAULT NULL,
  `reference_type` VARCHAR(60) DEFAULT NULL,
  `linked_requisition_id` VARCHAR(40) DEFAULT NULL,
  `date` DATE NOT NULL DEFAULT (CURRENT_DATE),
  `user_id` VARCHAR(40) NOT NULL,
  KEY `idx_st_item` (`item_id`),
  KEY `idx_st_type` (`type`),
  KEY `idx_st_date` (`date`),
  CONSTRAINT `fk_st_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_st_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grns` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `supplier_id` VARCHAR(40) NOT NULL,
  `grn_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_no` VARCHAR(100) DEFAULT NULL,
  `invoice_date` DATE DEFAULT NULL,
  `delivery_challan` VARCHAR(100) DEFAULT NULL,
  `delivery_date` DATE DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `total_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `attachments` TEXT DEFAULT NULL,
  CONSTRAINT `fk_grn_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grn_items` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `grn_id` VARCHAR(40) NOT NULL,
  `item_id` VARCHAR(40) NOT NULL,
  `received_qty` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  CONSTRAINT `fk_grni_grn` FOREIGN KEY (`grn_id`) REFERENCES `grns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grni_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `issuance_items` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `issuance_id` VARCHAR(40) NOT NULL,
  `item_id` VARCHAR(40) NOT NULL,
  `issued_qty` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT `fk_issi_issuance` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `auto_approval_settings` (
  `id` TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `priorities` VARCHAR(100) NOT NULL DEFAULT 'LOW'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(80) NOT NULL PRIMARY KEY,
  `setting_value` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `actor_id` VARCHAR(40) NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `entity` VARCHAR(60) NOT NULL,
  `entity_id` VARCHAR(60) NOT NULL,
  `before_json` TEXT DEFAULT NULL,
  `after_json` TEXT DEFAULT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_audit_timestamp` (`timestamp`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` VARCHAR(40) NOT NULL PRIMARY KEY,
  `user_id` VARCHAR(40) NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `link` VARCHAR(191) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO `departments` (`id`,`name`) VALUES
('dept-1','Marketing'),
('dept-2','Finance'),
('dept-3','HR'),
('dept-4','Operations'),
('dept-5','IT');

-- Plaintext passwords for all seeded users: "password"
-- (bcrypt hashes are generated for that string)
INSERT INTO `users` (`id`,`name`,`email`,`password_hash`,`role`,`department_id`,`approver_id`,`is_active`) VALUES
('user-1','Rahul Sharma','rahul@srims.com','$2y$12$qmtzu0f2.am4ApWc.loljevjRHIR944EzoKQskGYXn3wS2FWRX6/2','ADMIN','dept-1',NULL,1),
('user-3','Amit Verma','amit@srims.com','$2y$12$QhLFSGQkf1tC8F8SxLBgUu5.oKcFtzNCjZr6NddnrM.z46ps9qxVW','APPROVER','dept-1',NULL,1),  -- approver inserted before users that reference him
('user-2','Priya Singh','priya@srims.com','$2y$12$I.nbkgEKb5XhyecZK2d08O/JW9OK3xmx37Cbc7pFTM2fplzIvs1ZG','USER','dept-1','user-3',1),
('user-4','Sandeep Kumar','sandeep@srims.com','$2y$12$YEgfh4jcAhSsoo.TxysdT.wGZLUWoo3ch9IACiO0NA84UqYOR2h7q','INVENTORY_MGR','dept-4',NULL,1),
('user-5','Neha Gupta','neha@srims.com','$2y$12$cSbLVtpNt4Gafq7.812GjONultYmcDAbbrpvLBT4V.kaGMahXrLLy','USER','dept-2','user-3',1),
('user-6','Rohit Kumar','rohit@srims.com','$2y$12$fLciJWWR1J/4nqVdK1kkee5i2SEhoPDbn06./s156BDyMuISpZYcK','USER','dept-3','user-3',1),
('user-7','Sneha Iyer','sneha@srims.com','$2y$12$R9rrBTN17Y4oHcbecMSvbOmlACLIaxbSUEREV0bMudt/fKua9bZtq','USER','dept-5','user-3',1);

INSERT INTO `categories` (`id`,`name`,`parent_id`,`icon`,`color`,`bg_color`) VALUES
('cat-1','Writing Instruments',NULL,'PenTool','#2563EB','#DBEAFE'),
('cat-2','Paper Products',NULL,'FileText','#D97706','#FEF3C7'),
('cat-3','Desk Accessories',NULL,'Briefcase','#059669','#D1FAE5'),
('cat-4','Files & Folders',NULL,'Folder','#CA8A04','#FEF9C3'),
('cat-5','Office Electronics',NULL,'Calculator','#475569','#F1F5F9'),
('cat-6','Others',NULL,'MoreHorizontal','#6B7280','#F3F4F6');

INSERT INTO `items` (`id`,`name`,`category_id`,`unit`,`unit_price`,`current_stock`,`min_stock_level`,`is_active`,`icon_key`) VALUES
('ITM-0001','Ball Pen (Blue)','cat-1','Piece',5.0,15,50,1,'pen-blue'),
('ITM-0002','Ball Pen (Red)','cat-1','Piece',5.0,120,50,1,'pen-red'),
('ITM-0003','Marker (Black)','cat-1','Piece',18.0,5,30,1,'marker'),
('ITM-0004','Pencil (HB)','cat-1','Piece',4.0,200,100,1,'pencil'),
('ITM-0005','Highlighter (Yellow)','cat-1','Piece',15.0,2,25,1,'highlighter'),
('ITM-0006','Stapler Pins (10 No.)','cat-3','Box',20.0,85,30,1,'stapler-pins'),
('ITM-0007','A4 Copier Paper (70 GSM)','cat-2','Ream',210.0,8,20,1,'paper-a4'),
('ITM-0008','Spiral Notebook (A5)','cat-2','Piece',45.0,65,30,1,'notebook'),
('ITM-0009','File Folder','cat-4','Piece',25.0,45,20,1,'folder'),
('ITM-0010','Eraser','cat-3','Piece',5.0,150,50,1,'eraser'),
('ITM-0011','Gel Pen (Black)','cat-1','Piece',12.0,90,40,1,'pen-black'),
('ITM-0012','Whiteboard Marker','cat-1','Piece',25.0,7,20,1,'marker-wb'),
('ITM-0013','Sticky Notes (3x3)','cat-2','Pack',35.0,40,15,1,'sticky-notes'),
('ITM-0014','Paper Clips','cat-3','Box',10.0,100,25,1,'clips'),
('ITM-0015','Stapler','cat-3','Piece',120.0,12,5,1,'stapler'),
('ITM-0016','Scissors','cat-3','Piece',45.0,18,8,1,'scissors'),
('ITM-0017','Tape (Transparent)','cat-3','Roll',15.0,4,15,1,'tape'),
('ITM-0018','Envelope (A4)','cat-2','Pack',30.0,55,20,1,'envelope'),
('ITM-0019','Correction Pen','cat-1','Piece',20.0,35,15,1,'correction'),
('ITM-0020','Calculator (Basic)','cat-5','Piece',250.0,0,5,1,'calculator'),
('ITM-0021','Rubber Bands','cat-6','Pack',8.0,75,20,1,'rubber-bands'),
('ITM-0022','Glue Stick','cat-6','Piece',15.0,3,10,1,'glue'),
('ITM-0023','Lever Arch File','cat-4','Piece',85.0,6,10,1,'arch-file'),
('ITM-0024','Desk Organizer','cat-3','Piece',180.0,0,3,1,'organizer'),
('ITM-0025','USB Flash Drive (32GB)','cat-5','Piece',350.0,0,5,1,'usb');

INSERT INTO `suppliers` (`id`,`name`,`contact`,`address`) VALUES
('sup-1','ABC Stationery Suppliers','+91 98765 43210','12, MG Road, Kolkata 700001'),
('sup-2','Delhi Office Supplies Pvt. Ltd.','+91 98765 43211','45, Connaught Place, New Delhi 110001'),
('sup-3','Sharma Trading Co.','+91 98765 43212','78, Park Street, Kolkata 700016');

INSERT INTO `requisitions` (`id`,`user_id`,`department_id`,`status`,`purpose`,`remarks`,`required_date`,`total_amount`,`priority`,`created_at`,`approved_by_id`,`approved_at`,`rejected_reason`) VALUES
('REQ-2025-00128','user-2','dept-1','PENDING','Marketing Campaign','Urgent requirement for upcoming event','2025-06-05',1250.0,'URGENT','2025-05-31 10:30:00',NULL,NULL,NULL),
('REQ-2025-00127','user-3','dept-1','APPROVED','Monthly office supplies','','2025-06-03',630.0,'NORMAL','2025-05-30 14:20:00','user-1','2025-05-30 16:00:00',NULL),
('REQ-2025-00126','user-5','dept-2','ISSUED','Office Use','Standard monthly replenishment','2025-06-01',2310.0,'NORMAL','2025-05-29 09:15:00','user-3','2025-05-29 11:30:00',NULL),
('REQ-2025-00125','user-6','dept-3','PENDING','Training Session','For new joiner orientation week','2025-06-07',850.0,'NORMAL','2025-05-29 11:00:00',NULL,NULL,NULL),
('REQ-2025-00124','user-7','dept-5','REJECTED','Office Use','','2025-06-01',460.0,'LOW','2025-05-28 15:45:00','user-3',NULL,'Budget exceeded for this quarter. Please resubmit next month with revised quantities.'),
('REQ-2025-00123','user-2','dept-1','ISSUED','Event Preparation','','2025-05-28',1520.0,'URGENT','2025-05-26 09:00:00','user-3','2025-05-26 10:30:00',NULL),
('REQ-2025-00122','user-5','dept-2','APPROVED','Quarterly Audit','Required for audit documentation','2025-06-10',975.0,'NORMAL','2025-05-25 13:30:00','user-3','2025-05-25 15:00:00',NULL),
('REQ-2025-00121','user-6','dept-3','DRAFT','Office Use','Draft — reviewing quantities','2025-06-15',350.0,'LOW','2025-05-24 16:00:00',NULL,NULL,NULL),
('REQ-2025-00120','user-7','dept-5','PARTIAL','IT Department Supplies','Some items out of stock','2025-05-30',1180.0,'NORMAL','2025-05-23 10:00:00','user-3','2025-05-23 12:00:00',NULL),
('REQ-2025-00119','user-2','dept-1','PENDING','Marketing Campaign','','2025-06-08',540.0,'NORMAL','2025-05-22 08:30:00',NULL,NULL,NULL),
('REQ-2025-00118','user-5','dept-2','ISSUED','Office Use','','2025-05-25',420.0,'LOW','2025-05-20 11:00:00','user-3','2025-05-20 14:00:00',NULL),
('REQ-2025-00117','user-6','dept-3','APPROVED','Workshop','Annual HR workshop','2025-06-12',1650.0,'NORMAL','2025-05-18 09:00:00','user-3','2025-05-18 11:30:00',NULL),
('REQ-2025-00116','user-2','dept-1','REJECTED','Marketing Event','','2025-05-22',3500.0,'LOW','2025-05-15 14:00:00','user-3',NULL,'Duplicate request. Please check REQ-2025-00123.'),
('REQ-2025-00115','user-7','dept-5','ISSUED','IT Supplies','','2025-05-20',960.0,'NORMAL','2025-05-14 09:00:00','user-3','2025-05-14 10:00:00',NULL),
('REQ-2025-00114','user-5','dept-2','DRAFT','Year End','Preparing for fiscal year end','2025-06-20',250.0,'LOW','2025-05-12 16:00:00',NULL,NULL,NULL),
('REQ-2025-00113','user-6','dept-3','PENDING','Recruitment Drive','','2025-06-05',720.0,'URGENT','2025-05-10 10:00:00',NULL,NULL,NULL),
('REQ-2025-00112','user-2','dept-1','ISSUED','Office Use','','2025-05-15',880.0,'NORMAL','2025-05-08 09:00:00','user-3','2025-05-08 10:00:00',NULL),
('REQ-2025-00111','user-7','dept-5','APPROVED','Server Room','Labels for server room cables','2025-05-18',310.0,'NORMAL','2025-05-05 14:30:00','user-3','2025-05-06 09:00:00',NULL);

INSERT INTO `requisition_items` (`id`,`requisition_id`,`item_id`,`requested_qty`,`approved_qty`,`issued_qty`,`unit_price`) VALUES
('ri-1','REQ-2025-00128','ITM-0001',50,0,0,5.0),
('ri-2','REQ-2025-00128','ITM-0007',5,0,0,210.0),
('ri-3','REQ-2025-00127','ITM-0008',10,10,0,45.0),
('ri-4','REQ-2025-00127','ITM-0006',9,9,0,20.0),
('ri-5','REQ-2025-00126','ITM-0007',10,10,10,210.0),
('ri-6','REQ-2025-00126','ITM-0014',3,3,3,10.0),
('ri-7','REQ-2025-00126','ITM-0009',1,1,1,25.0),
('ri-8','REQ-2025-00125','ITM-0008',10,0,0,45.0),
('ri-9','REQ-2025-00125','ITM-0004',100,0,0,4.0),
('ri-10','REQ-2025-00124','ITM-0015',2,0,0,120.0),
('ri-11','REQ-2025-00124','ITM-0016',2,0,0,45.0),
('ri-12','REQ-2025-00124','ITM-0019',5,0,0,20.0),
('ri-13','REQ-2025-00123','ITM-0001',100,100,100,5.0),
('ri-14','REQ-2025-00123','ITM-0008',20,20,20,45.0),
('ri-15','REQ-2025-00123','ITM-0004',30,30,30,4.0),
('ri-16','REQ-2025-00122','ITM-0009',15,15,0,25.0),
('ri-17','REQ-2025-00122','ITM-0023',5,5,0,85.0),
('ri-18','REQ-2025-00121','ITM-0013',10,0,0,35.0),
('ri-19','REQ-2025-00120','ITM-0020',2,2,0,250.0),
('ri-20','REQ-2025-00120','ITM-0025',2,2,1,350.0),
('ri-21','REQ-2025-00119','ITM-0003',30,0,0,18.0),
('ri-22','REQ-2025-00118','ITM-0007',2,2,2,210.0),
('ri-23','REQ-2025-00117','ITM-0008',30,30,0,45.0),
('ri-24','REQ-2025-00117','ITM-0001',30,30,0,5.0),
('ri-25','REQ-2025-00117','ITM-0004',30,30,0,4.0),
('ri-26','REQ-2025-00116','ITM-0025',10,0,0,350.0),
('ri-27','REQ-2025-00115','ITM-0001',20,20,20,5.0),
('ri-28','REQ-2025-00115','ITM-0011',20,20,20,12.0),
('ri-29','REQ-2025-00115','ITM-0013',10,10,10,35.0),
('ri-30','REQ-2025-00115','ITM-0017',10,10,10,15.0),
('ri-31','REQ-2025-00114','ITM-0009',10,0,0,25.0),
('ri-32','REQ-2025-00113','ITM-0008',8,0,0,45.0),
('ri-33','REQ-2025-00113','ITM-0001',72,0,0,5.0),
('ri-34','REQ-2025-00112','ITM-0001',20,20,20,5.0),
('ri-35','REQ-2025-00112','ITM-0005',20,20,20,15.0),
('ri-36','REQ-2025-00112','ITM-0010',50,50,50,5.0),
('ri-37','REQ-2025-00112','ITM-0006',3,3,3,20.0),
('ri-38','REQ-2025-00111','ITM-0003',5,5,0,18.0),
('ri-39','REQ-2025-00111','ITM-0017',10,10,0,15.0),
('ri-40','REQ-2025-00111','ITM-0021',5,5,0,8.0);

INSERT INTO `stock_transactions` (`id`,`type`,`item_id`,`quantity`,`unit_price`,`reference_no`,`date`,`user_id`) VALUES
('st-1','INWARD','ITM-0007',500,210.0,'GRN-2025-0045','2025-05-31','user-4'),
('st-2','OUTWARD','ITM-0001',50,5.0,'ISS-2025-00155','2025-05-31','user-4'),
('st-3','INWARD','ITM-0003',100,18.0,'GRN-2025-0044','2025-05-30','user-4'),
('st-4','OUTWARD','ITM-0008',25,45.0,'ISS-2025-00154','2025-05-30','user-4'),
('st-5','OUTWARD','ITM-0009',30,25.0,'ISS-2025-00153','2025-05-29','user-4'),
('st-6','INWARD','ITM-0001',200,5.0,'GRN-2025-0043','2025-05-28','user-4'),
('st-7','INWARD','ITM-0004',500,4.0,'GRN-2025-0042','2025-05-27','user-4'),
('st-8','OUTWARD','ITM-0004',100,4.0,'ISS-2025-00152','2025-05-27','user-4'),
('st-9','OUTWARD','ITM-0001',100,5.0,'ISS-2025-00151','2025-05-26','user-4'),
('st-10','INWARD','ITM-0005',50,15.0,'GRN-2025-0041','2025-05-25','user-4'),
('st-11','OUTWARD','ITM-0005',48,15.0,'ISS-2025-00150','2025-05-25','user-4'),
('st-12','INWARD','ITM-0008',100,45.0,'GRN-2025-0040','2025-05-24','user-4'),
('st-13','OUTWARD','ITM-0007',20,210.0,'ISS-2025-00149','2025-05-23','user-4'),
('st-14','ADJUSTMENT','ITM-0010',-5,5.0,'ADJ-2025-001','2025-05-22','user-4'),
('st-15','INWARD','ITM-0006',100,20.0,'GRN-2025-0039','2025-05-21','user-4'),
('st-16','OUTWARD','ITM-0010',50,5.0,'ISS-2025-00148','2025-05-20','user-4'),
('st-17','INWARD','ITM-0011',100,12.0,'GRN-2025-0038','2025-05-19','user-4'),
('st-18','OUTWARD','ITM-0011',20,12.0,'ISS-2025-00147','2025-05-19','user-4'),
('st-19','INWARD','ITM-0009',50,25.0,'GRN-2025-0037','2025-05-18','user-4'),
('st-20','OUTWARD','ITM-0013',10,35.0,'ISS-2025-00146','2025-05-17','user-4'),
('st-21','INWARD','ITM-0014',50,10.0,'GRN-2025-0036','2025-05-16','user-4'),
('st-22','OUTWARD','ITM-0017',10,15.0,'ISS-2025-00145','2025-05-15','user-4'),
('st-23','INWARD','ITM-0015',10,120.0,'GRN-2025-0035','2025-05-14','user-4'),
('st-24','OUTWARD','ITM-0001',20,5.0,'ISS-2025-00144','2025-05-13','user-4'),
('st-25','INWARD','ITM-0002',150,5.0,'GRN-2025-0034','2025-05-12','user-4'),
('st-26','OUTWARD','ITM-0006',15,20.0,'ISS-2025-00143','2025-05-11','user-4'),
('st-27','INWARD','ITM-0013',30,35.0,'GRN-2025-0033','2025-05-10','user-4'),
('st-28','ADJUSTMENT','ITM-0024',-2,180.0,'ADJ-2025-002','2025-05-09','user-4'),
('st-29','INWARD','ITM-0016',20,45.0,'GRN-2025-0032','2025-05-08','user-4'),
('st-30','OUTWARD','ITM-0002',30,5.0,'ISS-2025-00142','2025-05-07','user-4');

INSERT INTO `notifications` (`id`,`user_id`,`type`,`message`,`is_read`,`link`,`created_at`) VALUES
('notif-1','user-3','APPROVAL_REQUEST','New requisition REQ-2025-00128 awaiting your approval',0,'/approvals/pending','2025-05-31 10:31:00'),
('notif-2','user-3','APPROVAL_REQUEST','New requisition REQ-2025-00125 awaiting your approval',0,'/approvals/pending','2025-05-29 11:01:00'),
('notif-4','user-2','REQUISITION_ISSUED','Your requisition REQ-2025-00123 has been issued',1,'/requisitions/my','2025-05-26 12:00:00'),
('notif-5','user-4','LOW_STOCK','5 items are running low on stock',0,'/inventory/low-stock','2025-05-31 08:00:00');

INSERT INTO `audit_logs` (`id`,`actor_id`,`action`,`entity`,`entity_id`,`before_json`,`after_json`,`timestamp`) VALUES
('audit-1','user-2','CREATE','Requisition','REQ-2025-00128',NULL,NULL,'2025-05-31 10:30:00'),
('audit-2','user-1','APPROVE','Requisition','REQ-2025-00127',NULL,NULL,'2025-05-30 16:00:00'),
('audit-3','user-3','APPROVE','Requisition','REQ-2025-00126',NULL,NULL,'2025-05-29 11:30:00'),
('audit-4','user-3','REJECT','Requisition','REQ-2025-00124',NULL,NULL,'2025-05-28 16:00:00');

INSERT INTO `auto_approval_settings` (`id`, `enabled`, `priorities`) VALUES (1, 1, 'LOW');

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('short_supply_policy', 'pending'),
('email_notifications', '1'),
('default_min_stock', '20');

SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `app_settings` MODIFY COLUMN `setting_value` LONGTEXT DEFAULT NULL;
