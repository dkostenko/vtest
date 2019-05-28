CREATE TABLE users ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(255));
CREATE TABLE posts ("id" INTEGER PRIMARY KEY AUTOINCREMENT,"creator_id" INTEGER,"created_at" INTEGER, "removed" INTEGER, "text" VARCHAR(100));
CREATE TABLE followers ("id" INTEGER PRIMARY KEY AUTOINCREMENT,"src_id" INTEGER,"dst_id" INTEGER);
CREATE UNIQUE INDEX followers_unique_src_dst_ids ON followers ("src_id", "dst_id");