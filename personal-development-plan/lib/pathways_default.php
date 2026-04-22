<?php
/**
 * Default content for the "Pathways to Career, Personal, and Relationship
 * Fulfillment" section shown under From/Toward on the PDP page.
 *
 * Used as a fallback when a contract has no `pathways` value set, and as the
 * seed content when creating a new PDP.
 */

function pdp_default_pathways_html() {
    return <<<'HTML'
<p><strong>Pathways to Career, Personal, and Relationship Fulfillment</strong></p>
<p>Based on where you are now, to get where you're headed, here are the three key elements of our proposal for you and me to discuss, revise, expand on, and consider.</p>
<ol>
  <li><strong>Lifestyle Analysis and Planning</strong> — This interview assessment will provide:
    <ul>
      <li>An in-depth discussion to understand your values, beliefs, purpose, and goals and barriers to living them fully.</li>
      <li>A clear inventory of limiting beliefs holding you back with long-term strategies to overcome these.</li>
      <li>A plan and articulation of how elements of the program will work together.</li>
    </ul>
  </li>
  <li><strong>"LiveMORE" Program</strong>
    <ul>
      <li>This program builds skills and habits through weekly practice assignments.</li>
      <li>In combination with coaching, the program helps you apply learnings to your life.</li>
      <li>The program is comprised of five segments:
        <ul>
          <li>An orientation to develop self-awareness and emotional intelligence.</li>
          <li>Nourishment/self-care section to get in touch with feelings and needs.</li>
          <li>Relationships section to improve social skills and connections.</li>
          <li>Personal power section to build assertiveness and influence.</li>
          <li>Purposeful living to find more meaning day-to-day.</li>
        </ul>
      </li>
    </ul>
  </li>
  <li><strong>Coaching</strong>
    <ul>
      <li>Coaching provides an opportunity to receive feedback on developing skills and receive support with challenges as they arise.</li>
      <li>Regular coaching with Drs. Bob and Judith will encourage a holistic, balanced approach to career and life.
        <ul>
          <li>Career coaching and consulting will facilitate you working your way out into greater success in your career.</li>
          <li>Lifestyle, relationship, and social-emotional intelligence coaching will help you to break your mistaken beliefs and attain career and personal goals.</li>
          <li>Generally, Bob works with you on leadership, organizational development, vision, strategy, tactics, and daily application, while Judith works with you on emotional fulfillment and internal development toward rematrixing.</li>
        </ul>
      </li>
      <li>Life Assessment and purpose exploration retreat (1–3 days) to look back and assess affinities, capacities, skills, experience, and unfinished business, and to look forward with clear eyes and even more purposeful, fulfilling, service-filled movement.</li>
    </ul>
  </li>
</ol>
HTML;
}

/**
 * Resolve the pathways HTML for a contract row. Falls back to the default
 * when the stored value is null or whitespace-only.
 */
function pdp_resolve_pathways_html($contract) {
    $value = $contract['pathways'] ?? '';
    if (trim(strip_tags((string)$value)) === '') {
        return pdp_default_pathways_html();
    }
    return $value;
}
