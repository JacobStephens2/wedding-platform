ALTER TABLE gallery_photos
    ADD COLUMN story_section VARCHAR(50) DEFAULT NULL,
    ADD COLUMN story_position INT DEFAULT NULL;

-- Seed existing photos with their current story page assignments
UPDATE gallery_photos SET story_section = 'the_sidewalk', story_position = 1
    WHERE path = 'meeting/2024-11-15_Fusion_dance_at_Concierge_Ballroom.jpg';
UPDATE gallery_photos SET story_section = 'the_sidewalk', story_position = 2
    WHERE path = 'meeting/2024-11-17_Rittenhop_Dip_Landscape.jpg';
UPDATE gallery_photos SET story_section = 'pastaio', story_position = 1
    WHERE path = 'dating/2025-01-16_Mel_and_Jacob_2_dip_bw.jpg';
UPDATE gallery_photos SET story_section = 'pastaio', story_position = 2
    WHERE path LIKE '%Mardi_Gras%';
UPDATE gallery_photos SET story_section = 'proposal', story_position = 1
    WHERE path LIKE '%Proposal_One_Knee%';
UPDATE gallery_photos SET story_section = 'proposal', story_position = 2
    WHERE path LIKE '%Proposal_Closeup%';
UPDATE gallery_photos SET story_section = 'blessing', story_position = 1
    WHERE path LIKE '%Landscape_JM_at_Altar%';
UPDATE gallery_photos SET story_section = 'blessing', story_position = 2
    WHERE path LIKE '%JM_With_Parents%';
