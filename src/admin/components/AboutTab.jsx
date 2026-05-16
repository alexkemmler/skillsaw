export default function AboutTab() {
	const version = window.skillsawData?.version;

	return (
		<div className="skillsaw-about">
			{ version && (
				<p className="skillsaw-about-version">Skillsaw { version }</p>
			) }
			<div className="skillsaw-about-body">
				<h2>What is Skillsaw and how does it work?</h2>
				<p>
					Skillsaw is an RSM project that takes the first step toward a post-resume future,
					where we can evaluate candidates based on their actual skills.
				</p>
				<p>
					The current version allows you to place a chatbot on application pages that
					candidates can optionally engage with. The chatbot runs on Claude and accepts
					uploads of candidate work samples, or allows the candidate to critique documents
					we provide. Claude compares the uploads to our provided documents, or evaluates
					the sophistication of a candidate&rsquo;s document critique to gauge the
					candidate&rsquo;s skill level.
				</p>
				<p>
					The purpose is to help direct attention to candidates that may not have qualified
					for a role based on their work history alone. It does not attempt to make or
					guide hiring decisions beyond that.
				</p>
				<p>
					The chatbot will give a very coarse rating to each skill, roughly corresponding
					to: <strong>Super-skilled</strong>, <strong>Skill exists</strong>,{' '}
					<strong>Skill does not exist</strong>, <strong>Terrible</strong>. We feel that
					more nuanced judgments should be done by humans.
				</p>
				<p>
					These ratings are added to the notes in a candidate&rsquo;s profile in
					Greenhouse, and also show up in the Candidates dashboard in Skillsaw, along with
					a transcript of their interaction with the chatbot and any uploads.
				</p>

				<h2>How to use Skillsaw</h2>
				<p>
					First make sure API keys, user IDs, etc. are all present in the Settings window,
					and properly configured on the Anthropic and Greenhouse side.
				</p>
				<p>
					Next, choose an open role. Use that role ID (from the internal Greenhouse URL,
					not the public-facing URL on{' '}
					<a href="https://automattic.com" target="_blank" rel="noreferrer">
						automattic.com
					</a>
					) when you create the role in Skillsaw.
				</p>
				<p>When you configure the role in Skillsaw, you will need:</p>
				<ul>
					<li>Division</li>
					<li>Team</li>
					<li>Role name</li>
					<li>
						Role ID (from the internal Greenhouse URL, not{' '}
						<a href="https://automattic.com" target="_blank" rel="noreferrer">
							automattic.com
						</a>
						)
					</li>
					<li>
						At least one example document to serve as a reference of &ldquo;good quality
						work&rdquo; for the role
					</li>
					<li>
						<em>Optional:</em> A redacted / sanitized version of that document to show to
						candidates for the critique exercise
					</li>
					<li>
						A list of concrete skills that could be evaluated via work sample or
						critiquing the same
					</li>
					<li>Any notes for the candidate from Talent or the HM</li>
					<li>
						Any role-specific instructions from Talent or the HM for how Claude should
						evaluate the candidate&rsquo;s skills
					</li>
				</ul>
				<p>
					Once you create the role and set the status to Active, you can copy the embed
					code (shortcode) to the application.
				</p>
				<p>
					Once candidates have interacted with the chatbot and submitted their application,
					they will appear in the Candidates tab, and shortly after that their information
					will appear in the Notes section in Greenhouse.
				</p>
				<p>
					Candidates can be sorted / filtered by role or &ldquo;mode&rdquo;, meaning how
					they interacted with the bot. The transcript of their interaction with the bot
					can be viewed or downloaded from this screen.
				</p>
				<p>The skills evaluated by the bot for each candidate will appear as:</p>
				<ul>
					<li>
						<strong>Clearly demonstrated</strong> &mdash; excellent according to Claude
						and our prompts
					</li>
					<li>
						<strong>Demonstrated</strong> &mdash; the skill was shown in a valid but not
						necessarily good or bad way
					</li>
					<li>
						<strong>Not demonstrated</strong> &mdash; the bot did not detect evidence of
						the skill
					</li>
					<li>
						<strong>Below threshold</strong> &mdash; the candidate thoroughly demonstrated
						that they do <em>not</em> have the skill
					</li>
				</ul>
				<p>
					When a candidate has achieved &ldquo;Clearly demonstrated&rdquo; or
					&ldquo;Demonstrated&rdquo; for important skills, the hope is that they will
					receive additional review / screening when they might not have otherwise.
				</p>
				<p>
					Also, it can be a way for nominally qualified candidates to differentiate
					themselves as having strong skills in addition to those that appear on their
					resume and application.
				</p>
				<p>
					Right now Skillsaw is a WordPress plugin and just for internal testing on
					Automattic roles. We hope that it proves useful!
				</p>

				<p className="skillsaw-about-feedback">
					You can share feedback directly with{' '}
					<strong>Alex Kemmler</strong> @alexkemmler and{' '}
					<strong>Zander Rose</strong> @zanderros3.
				</p>
			</div>
		</div>
	);
}
